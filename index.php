<?php
/*
A simple marketplace example of timestamping JPEGs and TIFFs on the Po.et
blockchain. The timestamp is then embedded in the image via the EXIF metadata.
*/

require_once "vendor/autoload.php";

use lsolesen\pel\Pel;
use lsolesen\pel\PelDataWindow;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTiff;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelEntryCopyright;

$savedFile = '';
$msg = null;

if ($_FILES) {
    $file = array_pop($_FILES);

    $valid = true;

    if ($file['error'] != UPLOAD_ERR_OK) {
        $msg = 'No file uploaded or something went wrong.';
        $valid = false;
    } else if (empty(trim($_POST["author"]))) {
        $msg = 'Please specify an author.';
        $valid = false;
    } else if (empty(trim($_POST["token"]))) {
        $msg = 'Please specify your Frost API token.';
        $valid = false;
    } else {
        $recaptchaSecret = "6Lf3Ak0UAAAAAP_PJIHxx_JVvU8TzoAWnq8W5Njp";
        $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".
        $recaptchaSecret."&response=".$_POST['g-recaptcha-response']);
        $response = json_decode($response, true);

        if($response["success"] === false) {
            $msg = 'Please verify that you are not a robot.';
            $valid = false;
        }
    }

    if ($valid) {
        $data = new PelDataWindow(file_get_contents($file['tmp_name']));

        if (!PelJpeg::isValid($data) && !PelTiff::isValid($data)) {
            print("Unrecognized image format! The first 16 bytes follow:\n");
            PelConvert::bytesToDump($data->getBytes(0, 16));
        } else {
            if (PelJpeg::isValid($data)) {
                $img = new PelJpeg();
            } elseif (PelTiff::isValid($data)) {
                $img = new PelTiff();
            }

            /* Try loading the data. */
            $img->load($data);

            if (!$img->getExif() && $img->getExif()->getTiff()) {
                $msg = "No EXIF data found.";
            } else {
                $ifd0 = $img->getExif()->getTiff()->getIfd();

                if ($ifd0) {
                    // if copyright not applied we can copyright via the blockchain
                    if (!($ifd0->getEntry(PelTag::COPYRIGHT) && trim($ifd0->getEntry(PelTag::COPYRIGHT)->getText()))) {
                        $work = [];

                        if ($title = $ifd0->getEntry(PelTag::DOCUMENT_NAME)) {
                            $work['name'] = trim($title->getText());
                        } else {
                            $work['name'] = trim($file["name"]);
                        }

                        $dateTimeOriginal = $ifd0->getEntry(PelTag::DATE_TIME_ORIGINAL);
                        $dateTime = $ifd0->getEntry(PelTag::DATE_TIME);
                        $now = date("Y-m-d H:i:s");

                        if ($dateTimeOriginal && trim($dateTimeOriginal->getText())) {
                            $date = strtotime(trim($dateTimeOriginal->getText()));
                        } else if ($dateTime && trim($dateTime->getText())) {
                            $date = strtotime(trim($dateTime->getText()));
                        } else {
                            $date = $now;
                        }

                        $work['dateCreated'] = date(DATE_ISO8601, $date);
                        $work['datePublished'] = date(DATE_ISO8601, strtotime($now));

                        $exifMetadata = [];

                        foreach ($ifd0->getEntries() as $key=>$value) {
                            $exifMetadata[PelTag::getName($value->getIfdType(), $value->getTag())] = $value->getText();
                        }

                        $work['content'] = json_encode($exifMetadata);

                        $work['author'] = $_POST["author"];

                        try {
                            if ($response = publish($_POST["token"], $work)) {
                                $work["id"] = $response->workId;

                                $timestampImg = clone $img;

                                $ifd0 = $timestampImg->getExif()->getTiff()->getIfd();
                                $entry = $ifd0->getEntry(PelTag::COPYRIGHT);

                                if ($entry) {
                                    $entry->setValue($work->id);
                                } else {
                                    $copy = new PelEntryCopyright("Verified on Po.et: ".$work->id);
                                    $ifd0->addEntry($copy);
                                }

                                $path = buildPathFromHash($file);

                                if (!dir(dirname(__FILE__).'/images').pathinfo($path, PATHINFO_DIRNAME)) {
                                    mkdir(dirname(__FILE__).'/images/'.pathinfo($path, PATHINFO_DIRNAME), 0750, true);
                                }

                                $timestampImg->saveFile(dirname(__FILE__).'/images/'.$path);

                                $savedFile->path = './images/'.$path;
                                $savedFile->exifMetadata = $exifMetadata;
                                $savedFile->poetWork = $work;

                                // this is an example POE transaction and does not actually happen.
                                $savedFile->tx = new stdClass();
                                $savedFile->tx->cost = 0.01;
                                $savedFile->tx->currency = "POE";
                            } else {
                                print('Publishing to Po.et failed.');
                            }
                        } catch (Exception $e) {
                            $msg = $e->getMessage();
                        }
                    } else {
                        $msg = 'It appears this image has already been copyrighted.';
                    }
                } else {
                    $msg = 'No EXIF data available.';
                }
            }
        }
    }
}

function publish($token, $work)
{
    if (preg_match('/^[A-Za-z0-9._\-]+$/', $token)) {
        $ch = curl_init();

        // Set query data here with the URL
        curl_setopt($ch, CURLOPT_URL, 'https://api.frost.po.et/works');

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('token: '.$token, 'Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($work));

        $response = curl_exec($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if (curl_error($ch)) {
            throw new Exception(curl_error($ch));
            return null;
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpcode !== (int)200) {
            throw new Exception($header);
        } else {
            return json_decode($body);
        }
    } else {
        throw new Exception("Invalid token.");
    }
}

function buildPathFromHash($file)
{
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    $hash = hash_file('sha256', $file['tmp_name']);

    $parts = [];

    for ($i = 0; $i < 12; $i+=3) {
        $parts[] = substr($hash, $i, 3);
    }

    $parts[] = substr($hash, $i, strlen($hash));

    return implode('/', $parts).'.'.$ext;
}
?>
<!doctype html>

<html lang="en">
<head>
    <meta charset="utf-8">

    <title>Po.et Digital Asset Timestamp</title>
    <meta name="description" content="Po.et Digital Asset Timestamp.">
    <meta name="author" content="KnowledgeArc">

    <link
        href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm"
        crossorigin="anonymous"/>

    <style type="text/css">
    body > header,
    body > footer {
        background: #07416b;
        color: #fff;
    }

    body > header h1 {
        font-size: 1.8rem;
    }

    section > h3 {
        font-weight: bold;
        font-size: 1rem
    }

    #download {
        margin: 40px 0;
    }

    #download > section {
        margin: 20px 0;
    }

    #download > section > h3 {
        margin: 20px 0 0 0;
        display: block;
        width: 100%;
        border-bottom: 1px solid black;
    }

    #share > .icon {
        padding: 10px;
        color: White;
    }
    </style>
</head>

<body>
    <header>
        <div class="d-flex container">
            <div class="p-2 justify-content-center align-self-center">
                <h1>Po.et Digital Asset Timestamp</h1>
            </div>

            <div class="ml-auto p-2 justify-content-center align-self-center">
                <a href="https://www.knowledgearc.com/" class="flex-column-reverse">
                    <img
                        src="https://www.knowledgearc.com/wp-content/uploads/2016/11/knowledgearc-logo-header.png"
                        alt="KnowledgeArc"
                        id="logo"/>
                </a>
            </div>
        </div>
    </header>

    <div class="main-content container">
        <div class="blurb alert alert-primary" role="alert">
            <p>An example marketplace for attaching a Po.et timestamp to a JPEG or TIFF image using EXIF.</p>

            <p>This is a prototype only and may function incorrectly. Please only use your testnet Frost API token.</p>

            </p>This software comes with no warranty or guarantee.</p>
        </div>

        <?php if ($msg) : ?>
        <div class="alert alert-danger" role="alert"><?php echo $msg; ?></div>
        <?php endif; ?>

        <form id="form" method="post" enctype="multipart/form-data" class="container-fluid">
            <div class="form-group row">
                <label for="file" class="font-weight-bold">File</label>
                <input type="file" name="file" id="file" class="form-control"/>
                <small class="form-text text-muted">Max size 2MB, jpeg or tiff only.</small>
            </div>

            <div class="form-group row">
                <label for="file" class="font-weight-bold">Author</label>
                <input type="text" name="author" id="author" class="form-control"/>
                <small class="form-text text-muted">The original owner of the image.</small>
            </div>

            <div class="form-group row">
                <label for="file" class="font-weight-bold">Frost Token</label>
                <textarea name="token" id="token" class="form-control"></textarea>
                <small class="form-text text-muted">A valid Frost API token.</small>
            </div>

            <div class="form-group row">
                <div class="g-recaptcha" data-sitekey="6Lf3Ak0UAAAAAKVvOL8uj4XVHGa40qB-c8e-g97Y"></div>
            </div>

            <button id="submit" name="submit" class="btn btn-primary">Submit</button>
        </form>

        <div id="download" class="container">
            <?php if ($savedFile) : ?>
                <a href="<?php echo $savedFile->path; ?>"><i class="fas fa-download"></i>&nbsp;Download</a>

                <section id="transaction" class="row">
                    <h3>Transaction</h3>

                    <div class="col-12">Cost:&nbsp;<?php echo $savedFile->tx->cost; ?>&nbsp;<?php echo $savedFile->tx->currency; ?></div>
                    <div class="col-12">
                        <small class="text-muted">This is an example only. No charges were actually applied.</small>
                    </div>
                </section>

                <section id="exifMetadata" class="row">
                    <h3>EXIF</h3>

                    <ul class="container">
                        <?php foreach ($savedFile->exifMetadata as $key=>$value) : ?>
                        <li class="row">
                            <span class="exifMetadataKey col-2"><?php echo $key; ?>: </span>
                            <span class="exifMetadataValue col-10"><?php echo $value; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section id="poetWork" class="row">
                    <h3>Po.et</h3>

                    <ul class="container">
                        <?php foreach ($savedFile->poetWork as $key=>$value) : ?>
                        <li class="row">
                            <span class="poetWorkKey col-2"><?php echo $key; ?>: </span>
                            <span class="poetWorkValue col-10"><?php echo $value; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <footer>
        <div class="d-flex container">
            <div class="p-2 justify-content-center align-self-center">
            &copy; Copright 2018 KnowledgeArc Ltd
            </div>

            <div id="share" class="ml-auto p-2 justify-content-center align-self-center">
                <a href="https://www.facebook.com/KnowledgeArc/" class="icon">
                    <i class="fab fa-facebook-f fa-2x"></i>
                </a>

                <a href="https://twitter.com/knowledgearc" class="icon">
                    <i class="fab fa-twitter fa-2x"></i>
                </a>

                <a href="https://www.reddit.com/r/KnowledgeArcNetwork/" class="icon">
                    <i class="fab fa-reddit fa-2x"></i>
                </a>

                <a href="https://github.com/KnowledgeArcNetwork" class="icon">
                    <i class="fab fa-github fa-2x"></i>
                </a>
            </div>
        </div>
    </footer>

    <script
        src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.bundle.min.js"
        integrity="sha384-feJI7QwhOS+hwpX2zkaeJQjeiwlhOP+SdQDqhgvvo1DsjtiSQByFdThsxO669S2D"
        crossorigin="anonymous"></script>

    <script
        src='https://www.google.com/recaptcha/api.js'></script>

    <script
        defer
        src="https://use.fontawesome.com/releases/v5.0.8/js/all.js"
        integrity="sha384-SlE991lGASHoBfWbelyBPLsUlwY1GwNDJo3jSJO04KZ33K2bwfV9YBauFfnzvynJ"
        crossorigin="anonymous"></script>

    <!-- Start of StatCounter Code for Default Guide -->
    <!-- uncomment to enable in production -->
    <!--script type="text/javascript">
    var sc_project=11660829;
    var sc_invisible=1;
    var sc_security="f04adde3";
    </script>

    <script
        type="text/javascript"
        src="https://www.statcounter.com/counter/counter.js"
        async></script>

    <noscript>
        <div class="statcounter">
            <a
                title="Web Analytics"
                href="http://statcounter.com/"
                target="_blank">
                <img
                    class="statcounter"
                    src="//c.statcounter.com/11660829/0/f04adde3/1/" alt="Web Analytics">
            </a>
        </div>
    </noscript-->
    <!-- End of StatCounter Code for Default Guide -->
</body>
</html>

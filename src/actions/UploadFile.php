<?php
/**
 * UploadFile.php
 *
 * PHP version 5.4+
 *
 * @author    Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2014 Sweelix
 * @license   http://www.sweelix.net/license license
 * @version   1.0.3
 * @link      http://www.sweelix.net
 * @category  actions
 * @package   sweelix.yii2.plupload.actions
 */

namespace sweelix\yii2\plupload\actions;

use sweelix\yii2\plupload\components\UploadedFile;
use yii\web\Response;
use yii\base\Action;
use Yii;
use Exception;

/**
 * This UploadFile handle the xhr /swfupload process
 *
 * @author    Philippe Gaultier <pgaultier@sweelix.net>
 * @copyright 2010-2014 Sweelix
 * @license   http://www.sweelix.net/license license
 * @version   1.0.3
 * @link      http://www.sweelix.net
 * @category  actions
 * @package   sweelix.yii2.plupload.actions
 * @since     1.0.0
 */
class UploadFile extends Action
{
    /**
     * @var string define locale used for transliteration
     */
    public $locale = 'fr_FR.UTF8';

    public function generateToken()
    {
        $time = microtime();
        $time = str_replace('.', '', $time);
        $time = explode(' ', $time);
        return  base_convert((int)$time[0] ^ (int)$time[1], 10, 32);
    }
    /**
     * Run the action and perform the upload process
     *
     * @return void
     * @since  1.0.0
     */
    public function run()
    {
        try {
            Yii::$app->getSession()->open();
            $sessionId =  Yii::$app->getRequest()->get('key', Yii::$app->getSession()->getId());
            $chunk = Yii::$app->getRequest()->get('chunk', 0);
            $chunks =  Yii::$app->getRequest()->get('chunks', 0);
            $originalFileName =  Yii::$app->getRequest()->get('name', '');
            $id = Yii::$app->getRequest()->get('id', 'unk');

            setlocale(LC_ALL, $this->locale);
            $originalFileName = iconv('utf-8', 'ASCII//TRANSLIT//IGNORE', $originalFileName);
            setlocale(LC_ALL, 0);

            $originalFileName = preg_replace('/([^a-z0-9\._\-])+/iu', '-', $originalFileName);

            if ($chunks < 2) {
                // we are not chunking. we can generate token
                $fileName = $this->generateToken();
                $targetPath = Yii::getAlias(UploadedFile::$targetPath);
            } else {
                // we can only generate token for last one
                $targetPath = Yii::getAlias(UploadedFile::$targetPath).DIRECTORY_SEPARATOR.$sessionId.DIRECTORY_SEPARATOR.$id;
                $fileName = $originalFileName;
            }

            if (is_dir($targetPath) == false) {
                mkdir($targetPath, 0777, true);
            }

            $pseudoFileResponse = [
                'name' => $originalFileName,
                'tmp_name' => $fileName,
                'type' => 'application/octet-stream',
                'size' => 0,
                'error' => UPLOAD_ERR_OK,
            ];
            // Look for the content type header
            $contentType = null;
            if (isset($_SERVER["HTTP_CONTENT_TYPE"])) {
                $contentType = $_SERVER["HTTP_CONTENT_TYPE"];
            }
            if (isset($_SERVER["CONTENT_TYPE"]) == true) {
                $contentType = $_SERVER["CONTENT_TYPE"];
            }
            if (strpos($contentType, "multipart") !== false) {
                if ((isset($_FILES['file']['tmp_name']) == true) && (is_uploaded_file($_FILES['file']['tmp_name']) == true)) {
                    // Open temp file
                    $out = fopen($targetPath . DIRECTORY_SEPARATOR . $fileName, $chunk == 0 ? "wb" : "ab");
                    if ($out !== false) {
                        // Read binary input stream and append it to temp file
                        $in = fopen($_FILES['file']['tmp_name'], "rb");
                        if ($in !== false) {
                            while (($buff = fread($in, 4096))) {
                                fwrite($out, $buff);
                            }
                        } else {
                            $response['error'] = UPLOAD_ERR_PARTIAL;
                        }
                        fclose($in);
                        fclose($out);
                        @unlink($_FILES['file']['tmp_name']);
                        $pseudoFileResponse['size'] = filesize($targetPath . DIRECTORY_SEPARATOR . $fileName);
                        $pseudoFileResponse['type'] = $_FILES['file']['type'];
                    } else {
                        $pseudoFileResponse['error'] = UPLOAD_ERR_CANT_WRITE;
                    }
                } else {
                    $pseudoFileResponse['error'] = UPLOAD_ERR_NO_FILE;

                }
            } else {
                // Open temp file
                $out = fopen($targetPath . DIRECTORY_SEPARATOR . $fileName, $chunk == 0 ? "wb" : "ab");
                if ($out !== false) {
                    // Read binary input stream and append it to temp file
                    $in = fopen("php://input", "rb");
                    if ($in !== false) {
                        while (($buff = fread($in, 4096))) {
                            fwrite($out, $buff);
                        }
                        $pseudoFileResponse['size'] = filesize($targetPath . DIRECTORY_SEPARATOR . $fileName);
                    } else {
                        $pseudoFileResponse['error'] = UPLOAD_ERR_PARTIAL;
                    }
                    fclose($in);
                    fclose($out);
                } else {
                    $pseudoFileResponse['error'] = UPLOAD_ERR_CANT_WRITE;
                }
            }
            // before doing anything, should we rename the file (after chunking)
            if (($chunks >= 2) && ($chunk == ($chunks - 1))) {
                $newfileName = $this->generateToken();
                rename($targetPath . DIRECTORY_SEPARATOR . $fileName, Yii::getAlias(UploadedFile::$targetPath).DIRECTORY_SEPARATOR.$newfileName);
                $pseudoFileResponse['tmp_name'] = $newfileName;
            }


            Yii::$app->getResponse()->format = Response::FORMAT_JSON;
            return $pseudoFileResponse;
            // return Yii::$app->getResponse();
        } catch (Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            throw $e;
        }
    }
}

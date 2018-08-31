<?php

namespace eseperio\avatar\controllers;

use eseperio\avatar\Module;
use Yii;
use yii\base\Exception;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use yii\validators\ImageValidator;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Class DefaultController
 * @package eseperio\avatar\controllers
 * @property Module $module
 */
class AvatarController extends \yii\web\Controller
{

    const EVENT_AFTER_UPLOAD = 'avatarAfterUpload';

    public function behaviors()
    {

        return [
            'verb' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'upload' => ['POST']
                ]
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => 'true',
                        'actions' => ['upload'],
                        'roles' => ['@']
                    ],
                    [
                        'allow' => 'true',
                        'actions' => ['picture'],
                    ]
                ]

            ]

        ];
    }

    public function actionUpload()
    {
        $module = $this->module;
        $targetId = null;
        if (Yii::$app->user->can($module->adminPermission)) {
            $targetId = Yii::$app->request->post('avatarId');
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        $response = ['success' => false];
        if (Yii::$app->request->isPost) {
            $image = UploadedFile::getInstanceByName($module->attributeName);
            $validator = new ImageValidator([
                'mimeTypes' => $module->mimeTypes,
                'maxWidth' => 3000,
                'maxHeight' => 3000,
                'skipOnEmpty' => true,
            ]);
            if ($validator->validate($image, $error)) {
                try {
                    $newFilename = $module->getAvatarFileName($targetId, $module->originalSuffix);
                    $uploadDir = Yii::getAlias($module->uploadDir);
                    $thumbDir = Yii::getAlias($module->thumbsDir);

                    if ($module->createDirectories) {
                        FileHelper::createDirectory($uploadDir);
                        FileHelper::createDirectory($thumbDir);
                    }
                    $ext = $module->getExtension();
                    $originalFullPath = $uploadDir . DIRECTORY_SEPARATOR . $newFilename . $ext;
                    if (!$image->saveAs($originalFullPath)) {
                        throw new Exception('Save original image failed');
                    }

                    $this->trigger(self::EVENT_AFTER_UPLOAD);
                    $thumbInstance = Image::thumbnail($originalFullPath, $module->thumbWidth, $module->thumbHeight);
                    $thumbFileName = $module->getAvatarFileName($targetId);
                    $thumbInstance->save($thumbDir . DIRECTORY_SEPARATOR . $thumbFileName . $ext);
                    $response['success'] = true;
                } catch (\Throwable $e) {
                    $errorMsg = Yii::t('avatar', 'A problem ocurred uploading your picture. Contact administrator');
                    $response['error'] = YII_DEBUG ? $e->getMessage() : $errorMsg;
                }

            } else {
                $response['error'] = $error;
            }
        }


        return $response;
    }

    /**
     * Render the selected avatar, even if it is in a protected folder.
     * @param $id integer
     * @return mixed|\yii\console\Response|Response
     * @throws NotFoundHttpException
     */
    public function actionPicture($id)
    {
        $module = $this->module;
        $path = Yii::getAlias($module->thumbsDir) . DIRECTORY_SEPARATOR;
        Yii::$app->response->format = Response::FORMAT_RAW;
        $ext = $module->getExtension();
        $filename = $path . $id . $ext;
        if ($module->validatePath($path, $filename)) {
            if (file_exists($filename)) {
                switch ($module->outputFormat) {
                    case Module::FORMAT_JPG:
                        Yii::$app->response->headers->add('content-type', 'image/jpeg');
                        break;
                    case Module::FORMAT_PNG:
                        Yii::$app->response->headers->add('content-type', 'image/png');
                        break;
                }
                Yii::$app->response->data = file_get_contents($filename);

                return Yii::$app->response;
            }
        }
        Yii::$app->response->headers->add('content-type', 'image/svg+xml');
        /** @fixme: This is a temporary solution. Get better default pic managment */
        Yii::$app->response->data = base64_decode(explode(',', $module->defaultImageBlob)[1]);

        return Yii::$app->response;

    }
}
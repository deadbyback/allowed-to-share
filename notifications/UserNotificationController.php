<?php

namespace app\modules\notification\controllers;

use app\components\rest\RestfulResponse;
use app\models\frontend\IdentityFilterTrait;
use app\modules\notification\models\UserNotification;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

class UserNotificationController extends Controller
{
    use IdentityFilterTrait;


    private RestfulResponse $restfulResponse;


    public function __construct($id, $module, $config = [], RestfulResponse $rest)
    {
        $this->restfulResponse = $rest;
        parent::__construct($id, $module, $config);
    }

    
    /**
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }


    public function actionGetUnread()
    {
        return $this->restfulResponse->ok(
            UserNotification::getLastUnreadNotificationsForCurrentUser()
        );
    }

    
    public function actionGetLast()
    {
        return $this->restfulResponse->ok(
            UserNotification::getLastNotificationsForCurrentUser()
        );
    }

    
    public function actionMarkAsRead(int $notificationId)
    {
        return $this->restfulResponse->ok(
            [
                'success' => (new UserNotification)->readNotificationIfExists($notificationId)
            ]
        );
    }

    
    public function actionMarkAsReadAndReacted(int $notificationId)
    {
        return $this->restfulResponse->ok(
            [
                'success' => (new UserNotification)->readNotificationAndRespondIfExists($notificationId)
            ]
        );
    }
}

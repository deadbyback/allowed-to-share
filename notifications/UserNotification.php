<?php

namespace app\modules\notification\models;

use app\models\db\User;
use Yii;

/**
 * This is the model class for table "UserNotification".
 *
 * @property int $id
 * @property int $userId
 * @property int $notificationId
 * @property int $statusId
 * @property int|null $createdAt
 * @property int|null $readAt
 *
 * @property Notification $notification
 * @property User $user
 */
class UserNotification extends \yii\db\ActiveRecord
{
    public const STATUS_UNREAD = 1;
    public const STATUS_READ = 2;
    public const STATUS_READ_AND_NON_REACTED = 3;
    public const STATUS_READ_AND_REACTED = 4;


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'UserNotification';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['userId', 'notificationId'], 'required'],
            [['statusId', 'createdAt', 'readAt'], 'default', 'value' => null],
            [['statusId'], 'default', 'value' => self::STATUS_UNREAD],
            [['userId', 'notificationId', 'statusId', 'createdAt', 'readAt'], 'integer'],
            [['notificationId'], 'exist', 'skipOnError' => true, 'targetClass' => Notification::class, 'targetAttribute' => ['notificationId' => 'id']],
            [['userId'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['userId' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('common', 'ID'),
            'userId' => Yii::t('common', 'User ID'),
            'notificationId' => Yii::t('common', 'Notification ID'),
            'statusId' => Yii::t('common', 'Status ID'),
            'createdAt' => Yii::t('common', 'Created At'),
            'readAt' => Yii::t('common', 'Read At'),
        ];
    }

    /**
     * Gets query for [[Notification]].
     *
     * @return \yii\db\ActiveQuery|NotificationQuery
     */
    public function getNotification()
    {
        return $this->hasOne(Notification::class, ['id' => 'notificationId']);
    }

    /**
     * Gets query for [[User]].
     *
     * @return \yii\db\ActiveQuery|UserQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    /**
     * {@inheritdoc}
     * @return UserNotificationQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new UserNotificationQuery(get_called_class());
    }


    /**
     * @return UserNotification[]|array|null
     */
    public static function getLastUnreadNotificationsForCurrentUser(): ?array
    {
        try {
            return self::find()
                ->forCurrentUser()
                ->lastUnreadNotififcations()
                ->all();
        } catch (\Exception $exception) {
            return null;
        }
    }


    /**
     * @return UserNotification[]|array|null
     */
    public static function getLastNotificationsForCurrentUser(): ?array
    {
        try {
            return self::find()
                ->forCurrentUser()
                ->lastNotififcations()
                ->all();
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @todo add isNeedReaction flag to set STATUS_READ or STATUS_READ_AND_NON_REACTED
     * @param $userNotificationId
     * @return bool
     */
    public function readNotificationIfExists($userNotificationId): bool
    {
        try {
            $userNotification = self::findOne(['id' => $userNotificationId]);
            $userNotification->statusId = self::STATUS_READ;
            $userNotification->save();

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }


    /**
     * @param $userNotificationId
     * @return bool
     */
    public function readNotificationAndRespondIfExists($userNotificationId): bool
    {
        try {
            $userNotification = self::findOne(['id' => $userNotificationId]);
            $userNotification->statusId = self::STATUS_READ_AND_REACTED;
            $userNotification->save();

            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }
}

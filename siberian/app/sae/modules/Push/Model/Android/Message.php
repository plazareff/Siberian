<?php

use Siberian\Hook;

/**
 * Class Push_Model_Android_Message
 */
class Push_Model_Android_Message
{
    /**
     * @var \Siberian\CloudMessaging\Sender\Fcm
     */
    private $service_fcm = null;

    /**
     * @var null|Push_Model_Message
     */
    private $message = null;

    /**
     * @var null|Siberian_Log
     */
    private $logger = null;

    /**
     * Push_Model_Android_Message constructor.
     * @param \Siberian\CloudMessaging\Sender\Fcm $serviceFcm
     * @throws Zend_Exception
     */
    public function __construct($serviceFcm)
    {
        $this->service_fcm = $serviceFcm;
        $this->logger = Zend_Registry::get("logger");
    }

    /**
     * @param Push_Model_Message $message
     */
    public function setMessage(Push_Model_Message $message)
    {
        $this->message = $message;
    }

    /**
     * @return Push_Model_Message
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @throws Exception
     */
    public function push()
    {
        $error = null;
        $device = new Push_Model_Android_Device();
        $message = $this->getMessage();
        $app_id = $message->getAppId();

        // New standalone push
        $devices = [];
        if ($message->getIsStandalone() === true) {
            $_device = (new Push_Model_Android_Device())
                ->find($message->getToken(), "registration_id");
            if ($_device->getPushAlert() === 'enabled') {
                $devices = [$_device];
            }
        } else {
            if ($message->getSendToAll() == 0) {
                $category_message = new Topic_Model_Category_Message();
                $allowed_categories = $category_message->findCategoryByMessageId($message->getId());
            } else {
                $allowed_categories = null;
            }

            # Individual push, push to user(s)
            $selected_users = null;
            if (Push_Model_Message::hasIndividualPush()) {
                if ($message->getSendToSpecificCustomer() == 1) {
                    $customer_message = new Push_Model_Customer_Message();
                    $selected_users = $customer_message->findCustomersByMessageId($this->getMessage()->getId());
                }
            }

            $_dbDevices = $device->findByAppId($app_id, $allowed_categories, $selected_users);
            foreach ($_dbDevices as $_dbDevice) {
                if ($_dbDevice->getPushAlert() === 'enabled') {
                    $devices[] = $_dbDevice;
                }
            }
        }

        if (empty($devices)) {
            $this->service_fcm->logger->log('No Android devices found, aborting.');
            return;
        }

        $messagePayload = $this->buildMessage();

        $deviceByTokenFcm = [];
        $registrationTokensFcm = [];
        foreach ($devices as $device) {
            $deviceByTokenFcm[$device->getRegistrationId()] = $device;
            $registrationTokensFcm[] = $device->getRegistrationId();
        }

        if (empty($registrationTokensFcm)) {
            $this->service_fcm->logger->log('No Android devices registered for push, aborting.');
            return;
        }

        try {
            $this->_pushToProvider($this->service_fcm,
                $messagePayload, $registrationTokensFcm, $deviceByTokenFcm);
        } catch (\Exception $e) {
            // Ignore
            $this->service_fcm->logger->log($e->getMessage());
            // Rethrow exception!
            throw $e;
        }
    }

    /**
     * @param $provider
     * @param $message
     * @param $tokens
     * @param $deviceByToken
     * @throws Siberian_Exception
     */
    private function _pushToProvider($provider, $message, $tokens, $deviceByToken)
    {
        // Send message!
        $error = '';
        try {
            $aggregateResult = $provider->send($message, $tokens);

            foreach ($aggregateResult->getResults() as $result) {
                try {
                    # Fetch the device
                    $registrationId = $result->getRegistrationId();
                    if (isset($deviceByToken[$registrationId])) {
                        $device = $deviceByToken[$registrationId];
                    } else {
                        continue;
                    }

                    $messageId = $result->getMessageId();
                    $errorCode = $result->getErrorCode();
                    if (!empty($messageId)) {
                        $registrationId = $device->getDeviceUid() ?
                            $device->getDeviceUid() : $device->getRegistrationId();

                        // Very important code, this links push message to user/device!
                        $this->getMessage()->createLog($device, 1, $registrationId);
                    } else if (!empty($errorCode)) {
                        # Remove device from list

                        Hook::trigger("push.android.delete_token", [
                            "device" => $device
                        ]);

                        $device->delete();

                        $msg = sprintf("#810-01: Android Device with ID: %s, Token: %s, removed after push failed.",
                            $device->getId(), $registrationId);
                        $this->logger->info($msg, "push_android", false);
                    }
                } catch (Exception $e) {
                    $msg = sprintf("#810-06: Android Device with ID: %s, Token: %s failed ! Error message: %s.",
                        $device->getId(), $registrationId, $e->getMessage());
                    $this->logger->info($msg, "push_android", false);
                }
            }

        } catch (InvalidArgumentException $e) { # $deviceRegistrationId was null
            $error = sprintf("#810-03: PushFirebase InvalidArgumentException with error: %s.", $e->getMessage());
        } catch (\Siberian\CloudMessaging\InvalidRequestException $e) { # server returned HTTP code other than 200 or 503
            $error = sprintf("#810-04: PushFirebase InvalidRequestException with error: %s.", $e->getMessage());
        } catch (\Exception $e) { # message could not be sent
            $error = sprintf("#810-05: PushFirebase Exception with error: %s.", $e->getMessage());
        }

        if (!empty($error)) {
            throw new Siberian_Exception($error);
        }
    }

    /**
     * @return \Siberian\Service\Push\CloudMessaging\Message
     * @throws Zend_Exception
     */
    public function buildMessage()
    {
        $messagePayload = new \Siberian\Service\Push\CloudMessaging\Message();

        $message = $this->getMessage();

        $application = new Application_Model_Application();
        $application->find($message->getAppId());

        $pushColor = strtoupper($application->getAndroidPushColor() ?? '#0099C7');

        if (is_numeric($message->getActionValue())) {
            $option_value = new Application_Model_Option_Value();
            $option_value->find($message->getActionValue());

            // In case we use only value_id
            if (!$application || !$application->getId()) {
                $application = (new Application_Model_Application())->find($option_value->getAppId());
            }

            $mobileUri = $option_value->getMobileUri();
            if (preg_match('/^goto\/feature/', $mobileUri)) {
                $action_url = sprintf("/%s/%s/value_id/%s",
                    $application->getKey(),
                    $mobileUri,
                    $option_value->getId());
            } else {
                $action_url = sprintf("/%s/%sindex/value_id/%s",
                    $application->getKey(),
                    $option_value->getMobileUri(),
                    $option_value->getId());
            }
        } else {
            $action_url = $message->getActionValue();
        }

        $coverUrl = $message->getCoverUrl();
        if (!preg_match("#^https?://#", $coverUrl)) {
            $coverUrl = $message->getData("base_url") . $coverUrl;
        }

        // Double check after altering data ...
        if ($coverUrl === $message->getData("base_url")) {
            $coverUrl = "";
        }

        $messagePayload
            ->setMessageId($message->getMessageId() . uniqid('push_fcm_', true))
            ->setTitle($message->getTitle())
            ->setMessage($message->getText())
            ->setGeolocation($message->getLatitude(), $message->getLongitude(), $message->getRadius())
            ->setCover($coverUrl, $coverUrl, $message->getText())
            ->setDelayWithIdle(false)
            ->setTimeToLive(0)
            ->setSendUntil($message->getSendUntil() ? $message->getSendUntil() : "0")
            ->setActionValue($action_url);

        if ($message->getForceAppRoute() === true) {
            $messagePayload->setOpenWebview(false);
        } else {
            $messagePayload->setOpenWebview(!is_numeric($message->getActionValue()));
        }

        # Priority to custom image
        $customImage = $message->getCustomImage();
        $path_custom_image = path("/images/application" . $customImage);
        if (strpos($customImage, '/images/assets') === 0 &&
            is_file(path($customImage))) {
            $messagePayload->setImage($message->getData('base_url') . $customImage);
        } else if (is_readable($path_custom_image) && !is_dir($path_custom_image)) {
            $messagePayload->setImage($message->getData('base_url') . '/images/application' . $customImage);
        } else {
            # Default application image
            $application_image = $application->getAndroidPushImage();
            if (!empty($application_image)) {
                $messagePayload->setImage($message->getData("base_url") .
                    "/images/application" . $application_image);
            }
        }

        if ($message->getLongitude() &&
            $message->getLatitude()) {
            $messagePayload->contentAvailable(true);
        }

        // Sound Legacy HTTP Payload!
        $messagePayload->addData('soundname', 'sb_beep4');

        // High priority!
        $messagePayload->priority('high');

        // Silent push enforced!
        $isSilentPush = $message->getIsSilent();

        // Check for "implicit" silent
        $noTitle = trim($messagePayload->getData()['title']);
        $noBody = trim($messagePayload->getData()['message']);
        if (empty($noTitle) && empty($noBody)) {
            $isSilentPush = true;
        }

        // Notification for FCM latest
        if (!$isSilentPush) {
            $notification = new \Siberian\CloudMessaging\Notification();
            $notification->title($messagePayload->getData()['title']);
            $notification->body($messagePayload->getData()['message']);
            $notification->sound('sb_beep4');
            $notification->icon('ic_icon');
            $notification->color($pushColor);
            $notification->notificationPriority('high');
            $messagePayload->notification($notification);
        }

        // Trigger an event when the push message is parsed,
        $result = Hook::trigger('push.message.android.parsed',
            [
                'message' => $messagePayload,
                'application' => $application
            ]);

        $messagePayload = $result['message'];

        return $messagePayload;
    }


}

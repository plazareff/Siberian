<?php

use Siberian\AdNetwork;
use Siberian\Exception;
use Siberian\Version;

/**
 * Class Application_Controller_View_Abstract
 */
abstract class Application_Controller_View_Abstract extends Backoffice_Controller_Default
{
    /**
     * @var array
     */
    public $cache_triggers = [
        'save' => [
            'tags' => ['app_#APP_ID#'],
        ],
        'switchtoionic' => [
            'tags' => ['app_#APP_ID#'],
        ],
        'saveadvertising' => [
            'tags' => ['app_#APP_ID#'],
        ],
    ];

    /**
     *
     */
    public function loadAction()
    {
        $payload = [
            'title' => sprintf('%s > %s',
                __('Manage'),
                __('Application')
            ),
            'icon' => 'fa-mobile',
            'ionic_message' => __('If your app is already published on the stores, be sure you have sent an update with the Ionic version, and that this update has already been accepted, otherwise your app may be broken.')
        ];

        $this->_sendJson($payload);
    }

    public function findAction()
    {
        $request = $this->getRequest();
        if (Siberian\Version::is('SAE')) {
            $appId = 1;
            $application = Application_Model_Application::getInstance();
        } else {
            $appId = $request->getParam('app_id', null);
            $application = (new Application_Model_Application())->find($appId);
        }

        $admin = new Admin_Model_Admin();
        if (Siberian_Version::is('SAE')) {
            $admins = $admin->findAll()->toArray();
            $admin_owner = $admin;
            $admin_owner->setData(current($admins));
        } else {
            $admins = $admin->getAllApplicationAdmins($appId);
            $admin_owner = $application->getOwner();
        }

        $admin_list = [];
        foreach ($admins as $admin) {
            $_dataAdmin = $admin;
            $_dataAdmin['key'] = sha1($admin['firstname'] . $admin['admin_id']);

            $admin_list[] = $_dataAdmin;
        }

        //Replace $admin (not used anyway) by owner so you can't mistake $admin and $owner
        $owner = [
            "id" => $admin_owner->getId(),
            'name' => $admin_owner->getFirstname() . ' ' . $admin_owner->getLastname(),
            'email' => $admin_owner->getEmail(),
            'company' => $admin_owner->getCompany(),
            'phone' => $admin_owner->getPhone()
        ];
        if (Siberian\Version::is('PE')) {
            $owner +=
                ['whitelabel' => [
                    "exist" => $admin_owner->exist,
                    "host" => $admin_owner->host,
                    "is_active" => $admin_owner->is_active,
                    "smtp" => $admin_owner->smtp
                ]
                ];
        }

        $store_categories = Application_Model_Device_Ionic_Ios::getStoreCategeories();
        $devices = [];
        foreach ($application->getDevices() as $device) {
            $device->setName($device->getName());
            $device->setBrandName($device->getBrandName());
            $device->setStoreName($device->getStoreName());
            $device->setHasMissingInformation(
                !$device->getUseOurDeveloperAccount() &&
                (!$device->getDeveloperAccountUsername() || !$device->getDeveloperAccountPassword())
            );
            $data = $device->getData();
            if ($data['type_id'] === '2') {
                $keystore_path = \Core_Model_Directory::getBasePathTo("var/apps/android/keystore/$appId.pks");
                $data['Keystore'] = is_file($keystore_path);
            }
            $data['owner_admob_weight'] = (integer)$data['owner_admob_weight'];

            if ((int)$device->getTypeId() === 2) {
                try {
                    $data['versionCode'] = Application_Model_Device_Abstract::validatedVersion($device);
                } catch (\Exception $e) {
                    // Here we fix the version
                    $device->setVersion('1.0')->save();
                    $data['versionCode'] = Application_Model_Device_Abstract::validatedVersion($device);
                }
                $data['versionCode'] = Application_Model_Device_Abstract::formatVersionCode($data['versionCode']);
            }

            $devices[] = $data;
        }


        $data = [
            'owner' => $owner,
            'admin_list' => $admin_list,
            'app_store_icon' => $application->getAppStoreIcon(),
            'google_play_icon' => $application->getGooglePlayIcon(),
            'devices' => $devices,
            'url' => $application->getUrl(),
            'has_ios_certificate' => Push_Model_Certificate::getiOSCertificat($appId),
            'pem_infos' => Push_Model_Certificate::getInfos($appId),
        ];

        foreach ($store_categories as $name => $store_category) {
            if ($store_category->getId() == $application->getMainCategoryId()) {
                $data['main_category_name'] = $name;
            } else if ($store_category->getId() == $application->getSecondaryCategoryId()) {
                $data['secondary_category_name'] = $name;
            }
        }

        $data['bundle_id'] = $application->getBundleId();
        $data['package_name'] = $application->getPackageName();
        $data['is_active'] = $application->isActive();
        $data['is_locked'] = $application->isLocked();
        $data['pre_init'] = (boolean)$application->getPreInit();
        $data['disable_updates'] = (boolean)$application->getDisableUpdates();
        $data['can_be_published'] = $application->canBePublished();
        $data['owner_use_ads'] = (boolean)$application->getOwnerUseAds();
        $data['request_tracking_authorization'] = (boolean)$application->getRequestTrackingAuthorization();

        if ($application->getFreeUntil()) {
            $data['free_until'] = datetime_to_format($application->getFreeUntil(), Zend_Date::DATE_SHORT);
        }
        $data['android_sdk'] = Application_Model_Tools::isAndroidSDKInstalled();
        $data['apk'] = Application_Model_ApkQueue::getPackages($appId);
        $data['apk_service'] = Application_Model_SourceQueue::getApkServiceStatus($appId);
        $data['zip'] = Application_Model_SourceQueue::getPackages($appId);
        $data['queued'] = Application_Model_Queue::getPosition($appId);
        $data['confirm_message_domain'] = __('If your app is already published, changing the URL key or domain will break it. You will have to republish it. Change it anyway?');
        $data['confirm_message_owner'] = p__('backoffice_application', 'You will change the app owner. Are you sure of your choice?');
        $data['confirm_message_delete_admin'] = p__('backoffice_application', 'You are about to remove this admin. Are you sure?');
        $data['confirm_upload_keystore'] = p__('backoffice_application', 'Uploading a new keystore file can prevent your app to be updated on the play store. Are you sure to upload a new keystore ?');
        $data['filter_too_short'] = p__('backoffice_application', 'Filter must be at least 3 characters long!');
        $data['filter_no_result'] = p__('backoffice_application', 'No result for your request!');

        $application->addData($data);

        $data = [
            'application' => $application->getData(),
            'statuses' => Application_Model_Device::getStatuses(),
            'design_codes' => Application_Model_Application::getDesignCodes()
        ];

        $data['application']['disable_battery_optimization'] = (boolean)$data['application']['disable_battery_optimization'];
        $data['application']['use_ads'] = (boolean)$data['application']['use_ads'];
        $data['application']['test_ads'] = (boolean)$data['application']['test_ads'];
        $data['application']['mediation_facebook'] = (boolean)$data['application']['mediation_facebook'];
        $data['application']['mediation_startapp'] = (boolean)$data['application']['mediation_startapp'];

        $data["application"]["list_of_admins"] = [];

        $this->_sendJson($data);

    }

    public function saveAction()
    {
        try {
            $request = $this->getRequest();
            $data = $request->getBodyParams();

            if (empty($data['app_id'])) {
                throw new Exception(__('An error occurred while saving. Please try again later.'));
            }

            // Be sure all apps are Ionc!
            $data['design_code'] = 'ionic';

            $application = (new Application_Model_Application())->find($data['app_id']);
            if (!$application ||
                !$application->getId()) {
                throw new Exception(__('An error occurred while saving. Please try again later.'));
            }

            if (!empty($data['key'])) {

                $moduleNames = array_map('strtolower', Zend_Controller_Front::getInstance()->getDispatcher()->getSortedModuleDirectories());
                if (in_array($data['key'], $moduleNames, true)) {
                    throw new Exception(__('Your domain key "%s" is not valid.', $data['key']));
                }

                $dummy = (new Application_Model_Application())->find($data['key'], 'key');
                if ($dummy &&
                    $dummy->getId() &&
                    $dummy->getId() !== $application->getId()) {
                    throw new Exception(__('The key is already used by another application.'));
                }
            } else {
                throw new Exception(__('The key cannot be empty.'));
            }

            if (!empty($data['domain'])) {

                $data['domain'] = str_replace(['http://', 'https://'], '', $data['domain']);

                $tmp_url = str_replace(['http://', 'https://'], '', $this->getRequest()->getBaseUrl());
                $tmp_url = current(explode('/', $tmp_url));

                $tmp_domain = explode('/', $data['domain']);
                $domain = current($tmp_domain);
                if (preg_match('/^(www.)?(' . $domain . ')/', $tmp_url)) {
                    throw new Exception(__("You can't use this domain."));
                }

                $domain_folder = next($tmp_domain);
                $moduleNames = array_map('strtolower', Zend_Controller_Front::getInstance()->getDispatcher()->getSortedModuleDirectories());
                if (in_array($domain_folder, $moduleNames, false)) {
                    throw new Exception(__('Your domain key \'%s\' is not valid.', $domain_folder));
                }

                if (!Zend_Uri::check('http://' . $data['domain'])) {
                    throw new Exception(__('Please enter a valid URL'));
                }

                $dummy = (new Application_Model_Application())->find($data['domain'], 'domain');
                if ($dummy &&
                    $dummy->getId() &&
                    ($dummy->getId() !== $application->getId())) {
                    throw new Exception('The domain is already used by another application.');
                }

            }

            if (!empty($data['package_name'])) {
                $application->setPackageName($data['package_name']);
            }

            if (!empty($data['bundle_id'])) {
                $application->setBundleId($data['bundle_id']);
            }

            if (empty($data['free_until'])) {
                $data['free_until'] = null;
            } else {
                $data['free_until'] = new Zend_Date($data['free_until'], 'MM/dd/yyyy');
                $data['free_until'] = $data['free_until']->toString('yyyy-MM-dd HH:mm:ss');
            }

            if (array_key_exists('disable_battery_optimization', $data)) {
                $val = filter_var($data['disable_battery_optimization'], FILTER_VALIDATE_BOOLEAN);
                $application->setDisableBatteryOptimization($val ? 1 : 0);

                unset($data['disable_battery_optimization']);
            }

            if (array_key_exists('disable_updates', $data)) {
                $val = filter_var($data['disable_updates'], FILTER_VALIDATE_BOOLEAN);
                $application->setDisableUpdates($val ? 1 : 0);

                unset($data['disable_updates']);
            }

            if (array_key_exists('pre_init', $data)) {
                $val = filter_var($data['pre_init'], FILTER_VALIDATE_BOOLEAN);
                $application->setData('pre_init', $val ? 1 : 0);

                unset($data['pre_init']);
            }

            if (array_key_exists('request_tracking_authorization', $data)) {
                $val = filter_var($data['request_tracking_authorization'], FILTER_VALIDATE_BOOLEAN);
                $application->setData('request_tracking_authorization', $val ? 1 : 0);

                unset($data['request_tracking_authorization']);
            }

            $application->addData($data)->save();

            $payload = [
                'success' => true,
                'message' => __('Info successfully saved'),
                'bundle_id' => $application->getBundleId(),
                'package_name' => $application->getPackageName(),
                'url' => $application->getUrl(),
            ];

        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }

        $this->_sendJson($payload);
    }

    public function switchionicAction()
    {
        if ($data = Zend_Json::decode($this->getRequest()->getRawBody())) {

            try {

                if (empty($data["app_id"])) {
                    throw new Exception(__("An error occurred while saving. Please try again later."));
                }

                if (isset($data["design_code"]) && $data["design_code"] != Application_Model_Application::DESIGN_CODE_IONIC) {
                    throw new Exception(__("You can't go back with Angular."));
                }

                $application = new Application_Model_Application();
                $application->find($data["app_id"]);

                if (!$application->getId()) {
                    throw new Siberian_Exception(__("Application id %s not found.", $data["app_id"]));
                }

                $application->setDesignCode(Application_Model_Application::DESIGN_CODE_IONIC);

                if ($design_id = $application->getDesignId()) {

                    $design = new Template_Model_Design();
                    $design->find($design_id);

                    if ($design->getId()) {
                        $application->setDesign($design);
                        Template_Model_Design::generateCss($application, false, false, true);
                    }

                }

                $application->save();

                $data = [
                    "success" => 1,
                    "message" => __("Your application is now switched to Ionic"),
                    "design_code" => "ionic",
                ];

            } catch (Exception $e) {
                $data = [
                    "error" => 1,
                    "message" => $e->getMessage()
                ];
            }

            $this->_sendHtml($data);
        }
    }


    public function savedeviceAction()
    {

        try {
            $request = $this->getRequest();
            $values = $request->getBodyParams();

            if (empty($values['app_id']) || !is_array($values['devices']) || empty($values['devices'])) {
                throw new Exception('#783-01: ' . __('An error occurred while saving. Please try again later.'));
            }

            $application = (new Application_Model_Application())->find($values['app_id']);
            if (!$application &&
                !$application->getId()) {
                throw new Exception('#783-02: ' . __('An error occurred while saving. Please try again later.'));
            }

            $rVersionCode = null;
            foreach ($values['devices'] as $deviceData) {
                if (!empty($deviceData['store_url'])) {
                    if (stripos($deviceData['store_url'], 'https://') === false) {
                        $deviceData['store_url'] = 'https://' . $deviceData['store_url'];
                    }
                    if (!Zend_Uri::check($deviceData['store_url'])) {
                        throw new Exception(__('Please enter a correct URL for the %s store', $deviceData['name']));
                    }
                } else {
                    $deviceData['store_url'] = null;
                }

                $device = $application->getDevice($deviceData['type_id']);

                $currentVersion = $device->getVersion();
                if ($currentVersion !== $deviceData['version']) {
                    // Reset build number on version change!
                    $deviceData['build_number'] = 0;
                }

                if ((int)$deviceData['type_id'] === 2) {
                    $currentVersion = Application_Model_Device_Abstract::validatedVersion($device);
                    $newVersion = Application_Model_Device_Abstract::validatedVersion($device, $deviceData['version'], 1);

                    // Ask user to confirm intent!
                    if (($newVersion < $currentVersion) &&
                        !array_key_exists('confirm', $values)) {
                        throw new Exception(p__('application', 'The new version must be greater than the current one, please confirm if you really want to downgrade it.'), 100);
                    }

                    $rVersionCode = $newVersion;
                }

                $device
                    ->addData($deviceData)
                    ->save();
            }


            $payload = [
                'success' => true,
                'message' => __('Info successfully saved'),
                'versionCode' => Application_Model_Device_Abstract::formatVersionCode($rVersionCode),
            ];

        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];

            if ($e->getCode() === 100) {
                $payload['confirm'] = true;
                $payload['confirmText'] = p__('application', 'You are about to downgrade the version, after that you may not be able to update your Android application, please confirm if you are sure!');
            }
        }

        $this->_sendJson($payload);
    }


    public function saveadvertisingAction()
    {

        if ($data = Zend_Json::decode($this->getRequest()->getRawBody())) {

            try {

                if (empty($data["app_id"]) OR !is_array($data["devices"]) OR empty($data["devices"])) {
                    throw new Exception(__("An error occurred while saving. Please try again later."));
                }

                $application = new Application_Model_Application();
                $application->find($data["app_id"]);

                if (!$application->getId()) {
                    throw new Exception(__("An error occurred while saving. Please try again later."));
                }

                $application
                    ->setUseAds(filter_var($data['use_ads'], FILTER_VALIDATE_BOOLEAN))
                    ->setTestAds(filter_var($data['test_ads'], FILTER_VALIDATE_BOOLEAN))
                    ->save();

                // Mediation configuration
                if (AdNetwork::$mediationEnabled) {
                    $application
                        ->setMediationFacebook(filter_var($data['mediation_facebook'], FILTER_VALIDATE_BOOLEAN))
                        ->setMediationStartapp(filter_var($data['mediation_startapp'], FILTER_VALIDATE_BOOLEAN))
                        ->save();
                }

                // Only if it's enabled!
                if ($application->getUseAds()) {
                    foreach ($data["devices"] as $deviceData) {
                        if (in_array($deviceData["admob_app_id"], ['', 'ca-app-pub-0000000000000000~0000000000'], true)) {
                            throw new Exception(p__('backoffice_application', 'AdMob app id is required!'));
                        }

                        $device = $application->getDevice($deviceData["type_id"]);
                        $data_device_to_save = [
                            "admob_app_id" => trim($deviceData["admob_app_id"]),
                            "admob_id" => trim($deviceData["admob_id"]),
                            "admob_interstitial_id" => trim($deviceData["admob_interstitial_id"]),
                            "admob_type" => trim($deviceData["admob_type"])
                        ];
                        $device->addData($data_device_to_save)->save();
                    }
                }

                $data = [
                    "success" => 1,
                    "message" => __("Info successfully saved")
                ];

            } catch (\Exception $e) {
                $data = [
                    "error" => 1,
                    "message" => $e->getMessage()
                ];
            }

            $this->_sendHtml($data);
        }
    }

    public function removeadminAction()
    {
        try {
            $request = $this->getRequest();
            $data = $request->getBodyParams();
            $appId = $data['app_id'];
            $adminId = $data['admin_id'];

            if (empty($appId) || $adminId < 0) {
                throw new Exception(__("08-0001: An error occurred while saving. Please try again later."));
            }

            $admin = (new Admin_Model_Admin())->find($adminId);

            if (!$admin || !$admin->getId()) {
                throw new Exception(__("0980002: An error occurred while saving. Please try again later."));
            }

            // Save the new owner!
            $application = (new Application_Model_Application())->find($appId);
            if ($application->getAdminId() == $adminId) {
                throw new Exception(__("08-0003: An error occurred while saving. Please try again later."));
            }
            $application->removeAdmin($admin);

            $payload = [
                'success' => true,
                'message' => p__('backoffice_application', 'Application admin is removed!')
            ];
        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }

        $this->_sendJson($payload);
    }

    public function searchOwnerAction()
    {
        try {
            $request = $this->getRequest();
            $data = $request->getBodyParams();
            $textualFilter = trim($data['filter']);

            if (mb_strlen($textualFilter) < 3) {
                throw new Siberian\Exception('#4556-0998' . __('Filter must be at least 3 characters long.'));
            }

            $textualFilter = "%{$textualFilter}%";

            // list all platform admin to show them in drop down menu
            $appAdmins = [];
            $listAppAdmins = (new Admin_Model_Admin())->filterAdmins($textualFilter);
            foreach ($listAppAdmins as $appAdmin) {
                if (Siberian\Version::is('PE')) {
                    $owner_whitelabel = (new Whitelabel_Model_Editor())->findAll(
                        ['admin_id = ?' => $appAdmin->getadmin_id()]);

                    foreach ($owner_whitelabel as $owner_wl) {
                        $appAdmin->addData('exist', true);
                        $appAdmin->addData('host', $owner_wl->getHost());
                    }
                }

                $appAdmins[] = [
                    'admin_id' => $appAdmin->getadmin_id(),
                    'admin_email' => $appAdmin->getEmail(),
                    'admin_exist' => $appAdmin->getExist(),
                    'admin_host' => $appAdmin->getHost(),
                ];
            }

            $payload = [
                'success' => true,
                'collection' => $appAdmins
            ];
        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }

        $this->_sendJson($payload);
    }

    public function saveownerAction()
    {
        try {
            $request = $this->getRequest();
            $data = $request->getBodyParams();
            $appId = $data['app_id'];
            $ownerId = $data['owner_id'];

            if (empty($appId) || $ownerId < 0) {
                throw new Exception(__("09-0001: An error occurred while saving. Please try again later."));
            }

            $admin = (new Admin_Model_Admin())->find($ownerId);

            if (!$admin || !$admin->getId()) {
                throw new Exception(__("09-0002: An error occurred while saving. Please try again later."));
            }

            // Save the new owner!
            $application = new Application_Model_Application();
            $application->find($appId);
            $application->setAdminId($ownerId);
            $application->save();

            // It's the new owner, so we want to be sure he can add pages!
            $admin->setIsAllowedToAddPages(true);
            $application->addAdmin($admin);

            $payload = [
                'success' => true,
                'message' => p__('backoffice_application', 'Application owner is saved!')
            ];
        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }

        $this->_sendJson($payload);
    }

    public function savebannerAction()
    {

        if ($data = Zend_Json::decode($this->getRequest()->getRawBody())) {

            try {

                if (empty($data["app_id"])) {
                    throw new Exception(__("An error occurred while saving. Please try again later."));
                }

                $application = new Application_Model_Application();
                $application->find($data["app_id"]);

                if (!$application->getId()) {
                    throw new Exception(__("An error occurred while saving. Please try again later."));
                }

                $data_app_to_save = [
                    "banner_title" => $data["banner_title"],
                    "banner_author" => $data["banner_author"],
                    "banner_button_label" => $data["banner_button_label"]
                ];

                $application->addData($data_app_to_save)->save();

                foreach ($data["devices"] as $deviceData) {
                    $device = $application->getDevice($deviceData["type_id"]);
                    $data_device_to_save = [
                        "banner_store_label" => $deviceData["banner_store_label"],
                        "banner_store_price" => $deviceData["banner_store_price"]
                    ];
                    $device->addData($data_device_to_save)->save();
                }

                $data = [
                    "success" => 1,
                    "message" => __("Info successfully saved")
                ];

            } catch (Exception $e) {
                $data = [
                    "error" => 1,
                    "message" => $e->getMessage()
                ];
            }

            $this->_sendHtml($data);
        }
    }

    /**
     * @throws Siberian_Exception
     * @throws Zend_Exception
     * @throws \Siberian\Exception
     */
    public function downloadsourceAction()
    {
        $request = $this->getRequest();
        try {
            if (__getConfig('is_demo')) {
                throw new \Siberian\Exception(
                    __("This is a demo version, you can't download any source codes / APKs"));
            }

            $params = $request->getParams();
            if (empty($params)) {
                throw new \Siberian\Exception(__('Missing parameters for generation.'));
            }

            $application = new Application_Model_Application();

            if (empty($params['app_id']) OR empty($params['device_id'])) {
                throw new \Siberian\Exception('#908-00: ' . __('This application does not exist'));
            }

            $application->find($params['app_id']);
            if (!$application->getId()) {
                throw new \Siberian\Exception('#908-01: ' . __('This application does not exist'));
            }

            $mainDomain = __get('main_domain');
            if (empty($mainDomain)) {
                throw new \Siberian\Exception('#908-02: ' .
                    __('Main domain is required, you can set it in <b>Settings > General</b>'));
            }

            $application->setDesignCode('ionic');

            $application_id = $params['app_id'];
            $type = ($request->getParam('type') == 'apk') ? 'apk' : 'zip';
            $device = ($request->getParam('device_id') == 1) ? 'ios' : 'android';
            $noads = ($request->getParam('no_ads') == 1) ? 'noads' : '';
            $isApkService = $request->getParam('apk', false) === 'apk';
            $design_code = $request->getParam('design_code');
            $adminIdCredentials = $request->getParam('admin_id', 0);

            // Firebase Validation!
            if ($device === 'android') {
                $credentials = (new Push_Model_Firebase())
                    ->find($adminIdCredentials, 'admin_id');

                $credentials->checkFirebase();
            }

            if ($type == 'apk') {
                $queue = new Application_Model_ApkQueue();

                $queue->setAppId($application_id);
                $queue->setName($application->getName());
            } else {
                $queue = new Application_Model_SourceQueue();

                $queue->setAppId($application_id);
                $queue->setName($application->getName());
                $queue->setType($device . $noads);
                $queue->setDesignCode($design_code);
            }

            // New case for source to apk generator!
            if ($isApkService) {
                $queue->setIsApkService(1);
                $queue->setApkStatus('pending');
            }

            $queue->setHost($mainDomain);
            $queue->setUserId($this->getSession()->getBackofficeUserId());
            $queue->setUserType('backoffice');
            $queue->save();

            /** Fallback for SAE, or disabled cron */
            $reload = false;
            if (!Cron_Model_Cron::is_active()) {
                $cron = new Cron_Model_Cron();
                $value = ($type == 'apk') ? 'apkgenerator' : 'sources';
                $task = $cron->find($value, 'command');
                Siberian_Cache::__clearLocks();
                $siberian_cron = new Siberian_Cron();
                $siberian_cron->execute($task);
                $reload = true;
            }

            $more['apk'] = Application_Model_ApkQueue::getPackages($application->getId());
            $more['zip'] = Application_Model_SourceQueue::getPackages($application_id);
            $more['queued'] = Application_Model_Queue::getPosition($application_id);
            $more['apk_service'] = Application_Model_SourceQueue::getApkServiceStatus($application_id);

            $payload = [
                'success' => true,
                'message' => __('Application successfully queued for generation.'),
                'more' => $more,
                'reload' => $reload,
                'isApkService' => $isApkService,
            ];


        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }

        $this->_sendJson($payload);
    }

    public function cancelqueueAction()
    {
        try {
            if ($data = $this->getRequest()->getParams()) {

                $application_id = $data['app_id'];
                $type = ($this->getRequest()->getParam("type") == "apk") ? "apk" : "zip";
                $device = ($this->getRequest()->getParam("device_id") == 1) ? "ios" : "android";
                $noads = ($this->getRequest()->getParam("no_ads") == 1) ? "noads" : "";

                Application_Model_Queue::cancel($application_id, $type, $device . $noads);

                $more["zip"] = Application_Model_SourceQueue::getPackages($application_id);
                $more["queued"] = Application_Model_Queue::getPosition($application_id);

                $data = [
                    "success" => 1,
                    "message" => __("Generation cancelled."),
                    "more" => $more,
                ];

            } else {
                $data = [
                    "error" => 1,
                    "message" => __("Missing parameters for cancellation."),
                ];
            }
        } catch (Exception $e) {
            $data = [
                "error" => 1,
                "message" => $e->getMessage(),
            ];
        }


        $this->_sendHtml($data);
    }

    public function uploadcertificateAction()
    {

        if ($app_id = $this->getRequest()->getParam('app_id')) {

            try {

                if (empty($_FILES) || empty($_FILES['file']['name'])) {
                    throw new Exception('No file has been sent');
                }

                if (\Siberian\Version::is('SAE')) {
                    $application = Application_Model_Application::getInstance();
                    $app_id = $application->getId();
                } else {
                    $application = (new Application_Model_Application())->find($app_id);
                    if (!$application ||
                        !$application->getId()) {
                        throw new Exception(__('An error occurred while saving. Please try again later.'));
                    }
                }


                $base_path = Core_Model_Directory::getBasePathTo("var/apps/iphone/");
                if (!is_dir($base_path)) mkdir($base_path, 0775, true);
                $path = Core_Model_Directory::getPathTo("var/apps/iphone/");
                $adapter = new Zend_File_Transfer_Adapter_Http();
                $adapter->setDestination(Core_Model_Directory::getTmpDirectory(true));

                if ($adapter->receive()) {

                    $file = $adapter->getFileInfo();

                    $certificat = new Push_Model_Certificate();
                    $certificat->find(['type' => 'ios', 'app_id' => $app_id]);

                    if (!$certificat->getId()) {
                        $certificat->setType("ios")
                            ->setAppId($app_id);
                    }

                    $new_name = uniqid('cert_', true) . ".pem";
                    if (!rename($file['file']['tmp_name'], $base_path . $new_name)) {
                        throw new Exception(p__('application', 'An error occurred while saving. Please try again later.'));
                    }

                    $certificat->setPath($path . $new_name)
                        ->save();

                    $data = [
                        "success" => 1,
                        "pem_infos" => Push_Model_Certificate::getInfos($app_id),
                        "message" => __("The file has been successfully uploaded")
                    ];

                } else {
                    $messages = $adapter->getMessages();
                    if (!empty($messages)) {
                        $message = implode("\n", $messages);
                    } else {
                        $message = __("An error occurred during the process. Please try again later.");
                    }

                    throw new Exception($message);
                }
            } catch (Exception $e) {
                $data = [
                    "error" => 1,
                    "message" => $e->getMessage()
                ];
            }

            $this->_sendHtml($data);

        }

    }

    public function uploadkeystoreAction()
    {
        try {
            $request = $this->getRequest();
            $appId = $request->getParam('appId', null);
            $requestTimestamp = time();

            if (empty($appId)) {
                throw new Exception(p__('application', 'We are unable to find the application.'));
            }

            if (empty($_FILES) || empty($_FILES['file']['name'])) {
                throw new Exception(p__('application', 'Missing file.'));
            }

            if (Version::is('SAE')) {
                $application = Application_Model_Application::getInstance();
            } else {
                $application = (new Application_Model_Application())->find($appId);
                if (!$application || !$application->getId()) {
                    throw new Exception(p__('application', 'We are unable to find the application.'));
                }
            }

            $base_path = path('var/apps/android/keystore');
            $tmp_path = tmp(true);
            if (!mkdir($base_path, 0775, true) && !is_dir($base_path)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $base_path));
            }
            $adapter = new Zend_File_Transfer_Adapter_Http();
            $adapter->setDestination(tmp(true));

            if (!$adapter->receive()) {
                $messages = $adapter->getMessages();
                if (!empty($messages)) {
                    $message = implode("\n", $messages);
                    throw new Exception($message);
                }
                throw new Exception(p__('application', 'An unknown error occured during the file upload.'));
            }

            $file = $adapter->getFileInfo();
            $file_path = $file['file']['tmp_name'];
            $file_ext = pathinfo($file_path, PATHINFO_EXTENSION);
            if ($file_ext !== 'zip') {
                throw new Exception(p__('application', 'You must upload a zip archive containing the required files.'));
            }

            exec("unzip -o $file_path -d $tmp_path/$appId-$requestTimestamp-import", $output, $return);
            $file_list = scandir("$tmp_path/$appId-$requestTimestamp-import");

            if (!in_array('passwords.txt', $file_list, true)) {
                throw new Exception(p__('application', 'The file passwords.txt is missing'));
            }
            $passwords_raw = file("$tmp_path/$appId-$requestTimestamp-import/passwords.txt");

            // Get info from password file!
            $passwords = [];
            foreach ($passwords_raw as $line) {
                $values = explode(':', $line);
                $passwords[$values[0]] = $values[1];
            }
            $store_pass = trim($passwords['store_pass']);
            $key_pass = trim($passwords['key_pass']);
            $alias = trim($passwords['alias']);

            // Check if keystore / pfx file is there
            if (!in_array('cert.pfx', $file_list, true) &&
                !in_array('keystore.pks', $file_list, true)) {
                throw new Exception(p__('application', 'The file cert.pfx or keystore.pks is missing'));
            }
            // Backup current keystore pass and alias
            if (is_readable("$base_path/$appId.pks")) {
                $backupPath = "$base_path/backup_import_$appId-$requestTimestamp.pks";
                $command = "mv $base_path/$appId.pks $backupPath";
                exec($command, $output_mv, $return_mv);
                if (!is_readable($backupPath)) {
                    throw new Exception(p__('application', 'Error when creating a backup of the current keystore file!'));
                }
            }
            $currentPasswords = (new Application_Model_Device())->find([
                'app_id' => $appId,
                'type_id' => 2
            ])->getData();

            # creating zip file of previous passwords and keystore
            $passwords_to_file = "key_pass:" . $currentPasswords["key_pass"] . "\nstore_pass:" . $currentPasswords["store_pass"] . "\nalias:" . $currentPasswords["alias"];
            file_put_contents("$base_path/$appId-passwords.txt", $passwords_to_file);

            $command = "cd $base_path && zip $appId-$requestTimestamp-keystore.zip $appId-passwords.txt bck_import_$appId.pks";

            exec($command, $output, $return);
            if ($return !== 0) {
                throw new Exception(p__('application', 'Error when archiving previous keystore and passwords'));
            }

            #importing new keystore from .pfx
            if (in_array('cert.pfx', $file_list, true)) {
                $trick = escapeshellarg("$store_pass\n$store_pass\n$store_pass");
                $command = "printf $trick | keytool -importkeystore -srckeystore $tmp_path/$appId-$requestTimestamp-import/cert.pfx -srcstoretype pkcs12 -destkeystore $base_path/$appId.pks -deststoretype JKS > $base_path/$appId-import.log 2>&1";

                exec($command, $output, $return);
                if ($return !== 0) {
                    $error = file_get_contents("$base_path/$appId-import.log");
                    throw new Exception(p__('application', 'Error while converting to keystore file: %s', $error));
                }
                #getAlias
                $commandx = "cat $base_path/$appId-import.log";
                exec($commandx, $outputx, $returnx);
                $aliasL = preg_grep("/[^{]+(?=})/", $outputx);
                preg_match("/[^{]+(?=})/", end($aliasL), $alias);
                $alias = "{" . end($alias) . "}";
            }

            #importing new keystore from .pks
            if (in_array('keystore.pks', $file_list, true)) {
                $command = "cp -p $tmp_path/$appId-$requestTimestamp-import/keystore.pks $base_path/$appId.pks > $base_path/$appId-import.log 2>&1";
                exec($command, $output, $return);

                if ($return !== 0) {
                    $error = file_get_contents("$base_path/$appId-import.log");
                    throw new Exception(p__('application', 'Error while copying keystore file: %s', $error));
                }
                $command = "printf $store_pass | keytool -list -v -keystore $base_path/$appId.pks";

                exec($command, $output, $return);
                if ($return !== 0) {
                    $output = json_encode(preg_grep('/keytool error/i', $output));
                    throw new Exception(p__('application', 'Error when opening keystore with provided password: %s', $output));
                }

            }
            #importing new keystore from .jks
            if (in_array('keystore.jks', $file_list, true)) {
                $command = "cp -p $tmp_path/$appId-$requestTimestamp-import/keystore.jks $base_path/$appId.pks > $base_path/$appId-import.log 2>&1";
                exec($command, $output, $return);
                if ($return !== 0) {
                    $error = file_get_contents("$base_path/$appId-import.log");
                    throw new Exception(p__('application', 'Error while copying keystore file: %s', $error));
                }
                $command = "printf $store_pass | keytool -list -v -keystore $base_path/$appId.jks";
                exec($command, $output, $return);
                if ($return !== 0) {
                    $output = json_encode(preg_grep('/keytool error/i', $output));
                    throw new Exception(p__('application', 'Error when opening keystore with provided password: %s', $output));
                }

            }
            # Save new password in database

            $device = $application->getDevice(2);
            $device->setAlias($alias);
            $device->setKeyPass($key_pass);
            $device->setStorePass($store_pass);
            $device->save();

            # return
            $payload = [
                'success' => true,
                'device' => $device->getData(),
                'message' => p__('application', 'The keystore is imported successfully!')
            ];


        } catch (\Exception $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }

        $this->_sendJson($payload);

    }


}

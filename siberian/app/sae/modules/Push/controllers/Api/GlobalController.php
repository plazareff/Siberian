<?php

/**
 * Class Push_Api_GlobalController
 */
class Push_Api_GlobalController extends Api_Controller_Default
{
    /**
     * @var string
     */
    public $namespace = 'push';

    /**
     * @var array
     */
    public $secured_actions = [
        'list',
        'send',
    ];

    /**
     *
     */
    public function listAction()
    {
        try {
            if ($params = $this->getRequest()->getPost()) {
                $application_table = new Application_Model_Db_Table_Application();
                $all_applications = $application_table->findAllForGlobalPush();

                if (isset($params['admin_id'])) {
                    // Get apps that belong to the current admin!
                    $all_for_admin = $application_table->findAllByAdmin(
                        $this->getSession()->getAdminId()
                    )->toArray();

                    $filtered = array_map(function ($app) {
                        return $app['app_id'];
                    }, $all_for_admin);

                    // We keep only apps that belongs to the admin!
                    $applications = array_intersect($all_applications, $filtered);
                } else {
                    $applications = $all_applications;
                }

                $result = [];
                if (!empty($applications)) {
                    $ids = join(',', $applications);
                    $result = $application_table->getAdapter()->fetchAll('
                        SELECT `app_id`, `name`, `key`, `bundle_id`, `package_name`, `admin_id`
                        FROM `application`
                        WHERE `app_id` IN (' . $ids . ')
                    ');
                }

                $data = [
                    'success' => true,
                    'applications' => $result,
                    'debug' => "API override - " . date(DATE_RFC2822)
                ];
            } else {
                throw new Siberian_Exception(
                    __("%s, No params sent.",
                        "Push_Api_GlobalController::listAction"
                    )
                );
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message = (empty($message)) ?
                __("An unknown error occurred while listing applications.") :
                $message;

            $data = [
                "error" => true,
                "message" => $message,
            ];
        }
        $this->_sendJson($data);
    }

    /**
     *
     */
    public function sendAction()
    {
        try {
            if ($params = $this->getRequest()->getPost()) {

                $params["base_url"] = $this->getRequest()->getBaseUrl();

                if (empty($params["title"]) || empty($params["message"])) {
                    throw new Siberian_Exception(
                        __("Title & Message are both required.")
                    );
                }

                //PUSH TO USER ONLY
                if ($params["customers_receiver"]) {
                    if (Push_Model_Message::hasIndividualPush()) {

                        if(empty($params['app_id'])) {
                            throw new Siberian_Exception(
                                __("Please select at least one application.")
                            );
                        }

                        $message = new Push_Model_Message();
                        $sendNow = (empty($params['send_at']));

                        $params["target_devices"] = "all";

                        if (empty($params["target_devices"])) {
                            $params["target_devices"] = $params["target_devices"];
                        }

                        if (empty($params['action_value'])) {
                            $params['action_value'] = null;
                        } else if (!preg_match('/^[0-9]*$/', $params['action_value'])) {
                            $url = "http://" . $params['action_value'];
                            if (stripos($params['action_value'], "http://") !== false ||
                                stripos($params['action_value'], "https://") !== false) {
                                $url = $params['action_value'];
                            }

                            $params['action_value'] = file_get_contents("https://tinyurl.com/api-create.php?url=" . urlencode($url));
                        }

                        $params['type_id'] = ($params['type_id']) ? $params['type_id'] : 1;
                        $params["send_to_all"] = $params["topic_receiver"] ? 0 : 1;
                        $params['send_to_specific_customer'] = 1;

                        // Filter out unwanted params
                        $allowed_params = [
                            'app_id',
                            'send_at',
                            'target_devices',
                            'action_value',
                            'type_id',
                            'send_to_all',
                            'send_to_specific_customer'
                        ];

                        $data =[]; 
                        foreach($allowed_params as $k) {
                            $data[$k] = $params[$k];
                        }

                        $message->setData($data);

                        // Use new methods for automatic base64 conversion
                        $message->setTitle($params["title"])->setText($params["message"]);

                        $message->save();
                        $messageId = $message->getId();

                        $customers_data = explode(";", $params["customers_receiver"]);

                        foreach ($customers_data as $id_raw) {
                            $id_customer = trim($id_raw);
                            if ($id_customer != "") {
                                $customer_message = new Push_Model_Customer_Message();
                                $customer_message_data = [
                                    "customer_id" => $id_customer,
                                    "message_id" => $messageId
                                ];
                                $customer_message->setData($customer_message_data);
                                $customer_message->save();
                            }
                        }

                        if ($sendNow) $message = __('Your message has been saved successfully and will be sent in a few minutes');
                        else $message = __('Your message has been saved successfully and will be sent at the entered date');

                        $data = [
                            "success" => true,
                            "message" => $message,
                            "debug" => $this->getRequest()->getPost()
                        ];

                    } else {
                        throw new Siberian_Exception(
                            __("Individual Push Module not found.")
                        );
                    }
                } else {

                    // Filter checked applications!
                    $params["checked"] = array_keys(
                        array_filter($params["checked"], function ($v) {
                            return ($v == true);
                        })
                    );

                    if (empty($params["checked"]) && !$params["send_to_all"]) {
                        throw new Siberian_Exception(
                            __("Please select at least one application.")
                        );
                    }

                    $push_global = new Push_Model_Message_Global();
                    $result = $push_global->createInstance($params);

                    $data = [
                        "success" => true,
                        "message" => ($result) ?
                            __("Push message is sent.") :
                            __("No message sent, there is no available applications."),
                        "debug" => $this->getRequest()->getPost()
                    ];

                }
            } else {
                throw new Siberian_Exception(
                    __("%s, No params sent.",
                        "Push_Api_GlobalController::sendAction"
                    )
                );
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message = (empty($message)) ?
                __("An unknown error occurred while creating the push notification.")
                : $message;

            $data = [
                "error" => true,
                "message" => $message,
            ];
        }
        $this->_sendJson($data);
    }
}

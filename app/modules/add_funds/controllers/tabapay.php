<?php


class tabapay extends MX_Controller
{
    public $tb_users;
    public $tb_transaction_logs;
    public $tb_payments;
    public $tb_payments_bonuses;
    public $payment_type;
    public $payment_fee;
    public $currency_code;
    public $mode;
    public $MerchantID;
    public $payment_id;

    public function __construct($payment = "")
    {
        parent::__construct();
        $this->load->model('add_funds_model', 'model');

        $this->tb_users = USERS;
        $this->tb_transaction_logs = TRANSACTION_LOGS;
        $this->tb_payments = PAYMENTS_METHOD;
        $this->tb_payments_bonuses = PAYMENTS_BONUSES;
        $this->payment_type = "tabapay";
        $this->currency_code = get_option("currency_code", "IRT");
        if ($this->currency_code == "") {
            $this->currency_code = 'IRT';
        }

        if (!$payment) {
            $payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
        }

        $this->payment_id = $payment->id;
        $params = $payment->params;
        $option = get_value($params, 'option');
        $this->mode = get_value($option, 'environment');
        $this->payment_fee = get_value($option, 'tnx_fee');
        // options

        $this->MerchantID = get_value($option, 'MerchantID');

    }

    public function index()
    {
        redirect(cn("add_funds"));
    }

    /**
     *
     * Create payment
     *
     */
    public function create_payment($data_payment = "")
    {
        _is_ajax($data_payment['module']);
        $amount = $data_payment['amount'];


        if (!$amount) {
            _validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
        }

        if (!$this->MerchantID) {
            _validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail'));
        }

        $users = session('user_current_info');

        //محاسبه کارمزد تراکنش
        if ($this->payment_fee > 0) {
            $payment_fee = $amount * ($this->payment_fee / 100);
        } else {
            $payment_fee = 0;
        }


        $data = array(
            'amount' => (int)$amount * 10, //Rial
            'description' => 'شارژ اعتبار در' . get_option('website_name') . '. (' . $users['email'] . ')',
            'email' => $users['email'],
            'callbackURL' => cn("add_funds/tabapay/complete"),
			'additionalData' => json_encode(["uid" => session("uid")])
        );

        $responseData = $this->CreateTransaction($data);
        //Redirect to URL You can do it also by creating a form

        if (!empty($responseData) && $responseData['status'] == "success" && !empty($responseData['url'])) {
            $url = $responseData['url'];
            $data_tnx_log = array(
                "ids" => ids(),
                "uid" => session("uid"),
                "type" => $this->payment_type,
                "transaction_id" => $responseData['token'],
                "amount" => (int)$amount,
                'txn_fee' => (int)$payment_fee,
                "status" => 0,
                "created" => NOW,
            );

            $transaction_log_id = $this->db->insert($this->tb_transaction_logs, $data_tnx_log);

            if ($this->input->is_ajax_request()) {
                ms(['status' => 'success', 'redirect_url' => $url, 'message' => 'شما به زودی به درگاه هدایت خواهید شد']);
            }
        } else {
            $responseData = array(
                "status" => $responseData['status'],
                "responseCode" => $responseData['responseCode'],
                "message" => $responseData['message']
            );

            $error_message = $this->error($responseData['message']);
            _validation('error', $error_message);
        }
    }


    /**
     *
     * Call Execute payment after creating payment
     *
     */
    public function complete()
    {
        if (!isset($_GET['token'])) {
            redirect(cn("add_funds/unsuccess"));
        }

        $amount = $_GET['amount']/10;
        $transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $_GET['token'], 'status' => 0, 'amount' => $amount, 'type' => $this->payment_type]);
        
        if (!$transaction) {
            redirect(cn("add_funds"));
        }
        
        $uid = $transaction->uid;
        if(empty(session("uid")))
            set_session("uid",$uid);

        if ($_GET['status'] == 'success' && $_GET['responseCode'] == 1) {
            $responseData = $this->VerifyTransaction($_GET['token'], $_GET['amount']);

            if ($responseData['status'] == "success" && $responseData['responseCode'] == 1) {

                $data_tnx_log = array(
                    "transaction_id" => $responseData['trackingCode'],
                    "status" => 1,
                );

                $this->db->update($this->tb_transaction_logs, $data_tnx_log, ['id' => $transaction->id]);

                // Update Balance
                require_once 'add_funds.php';
                $add_funds = new add_funds();
                $add_funds->add_funds_bonus_email($transaction, $this->payment_id);
                set_session("transaction_id", $transaction->id);
                redirect(cn("add_funds/success"));

            } else {
                //حالت دیباگ اینجا فعال بشه
                if ($this->mode == "debug") {
                    echo $this->error($responseData['message']);
                    echo '<br>';
                    echo print_r($responseData);
                    die();
                }

                redirect(cn("add_funds/unsuccess"));
            }

        } else {
            //حالت دیباگ اینجا فعال بشه
            redirect(cn("add_funds/unsuccess"));
        }
    }

    //****************************************************************//

    public function CreateTransaction($data)
    {
        if ($this->mode == "sandbox") {//اگر حالت تست هست
            $url = 'https://api.tabapay.ir/v1/sandbox/create';
        } else {
            $url = 'https://api.tabapay.ir/v1/create';
        }

        // Request body
        $data = array(
            'amount' => $data['amount'],
            'callbackURL' => $data['callbackURL'],
            'mobile' => !empty($data['mobile']) ? $data['mobile'] : null,
            'email' => !empty($data['email']) ? $data['email'] : null,
            'name' => !empty($data['name']) ? $data['name'] : null,
            'sms' => !empty($data['sms']) ? $data['sms'] : null,
            'cardNumber' => !empty($data['cardNumber']) ? $data['cardNumber'] : null,
            'nationalCode' => !empty($data['nationalCode']) ? $data['nationalCode'] : null,
            'description' => !empty($data['description']) ? $data['description'] : null,
            'additionalData' => !empty($data['additionalData']) ? $data['additionalData'] : null
        );

        // Convert data to JSON format
        $postData = json_encode($data);

        // Send request and get response
        return $this->SendRequest("post", $url, $postData, $this->MerchantID);
    }

    public function VerifyTransaction($token, $amount)
    {
        if ($this->mode == "sandbox") {//اگر حالت تست هست
            $url = 'https://api.tabapay.ir/v1/sandbox/verify';
        } else {
            $url = 'https://api.tabapay.ir/v1/verify';
        }

        // Request body
        $data = array(
            'token' => $token,
            'amount' => $amount
        );

        // Convert data to JSON format
        $postData = json_encode($data);

        // Send request and get response
        return $this->SendRequest("post", $url, $postData, $this->MerchantID);
    }


    private function SendRequest($method, $url, $postData = null, $merchant = null)
    {
        // Request headers
        $headers = array(
            'Authorization: Bearer ' . $merchant,
            'Content-Type: application/json',
        );

        // Initialize cURL session
        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method == "get") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        }
        if ($method == "post") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        // Execute cURL session and get the response
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            echo 'Curl error: ' . curl_error($ch);
        }

        // Close cURL session
        curl_close($ch);

        // Decode the API response
        return json_decode($response,1);
    }
}
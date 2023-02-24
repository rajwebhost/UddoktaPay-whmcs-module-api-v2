<?php

/**
 * UuddoktaPay WHMCS Gateway
 *
 * Copyright (c) 2022 UuddoktaPay
 * Website: https://uddoktapay.com
 * Developer: rtrasel.com
 * 
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Config\Setting;

class UddoktaPay
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var int
     */
    public $invoiceID;

    /**
     * @var float
     */
    public $total;

    /**
     * UddoktaPay constructor.
     */
    public function __construct()
    {
        $this->setGateway();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new UddoktaPay;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = !empty($this->gatewayParams['type']);
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->invoiceID
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI(
            'GetTransactions',
            ['transid' => $trxId]
        );
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            $payload,
            $payload['status']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Execute the payment by ID.
     *
     * @return array
     */
    private function executePayment()
    {
        $headerApi = isset($_SERVER['HTTP_RT_UDDOKTAPAY_API_KEY']) ? $_SERVER['HTTP_RT_UDDOKTAPAY_API_KEY'] : null;

        if ($headerApi == null) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid API Key.'
            ];
        }

        $apiKey = trim($this->gatewayParams['apiKey']);

        if ($headerApi != $apiKey) {
            return [
                'status'    => 'error',
                'message'   => 'Unauthorized Action.'
            ];
        }

        $response = file_get_contents('php://input');

        if (!empty($response)) {

            $data = json_decode($response, true);

            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from UddoktaPay API.'
        ];
    }

    /**
     *
     * Execute payment v2
     *
     */
    private function executePaymentV2()
    {
        if (!isset($_POST['invoice_id'])) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid Response.'
            ];
        }

        // Global Data
        $apiUrl = trim($this->gatewayParams['apiUrl']);
        $host = parse_url($apiUrl,  PHP_URL_HOST);
        $verifyUrl = "https://{$host}/api/verify-payment";
        
        $apiKey = trim($this->gatewayParams['apiKey']);

        // Generate API URL
        $invoice_id = strip_tags(trim($_POST['invoice_id']));

        // Set data
        $data = [
            'invoice_id'    => $invoice_id
        ];

        // Setup request to send json via POST.
        $headers = [];
        $headers[] = "Content-Type: application/json";
        $headers[] = "RT-UDDOKTAPAY-API-KEY:" . $apiKey;

        // Contact UuddoktaPay Gateway and get URL data
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verifyUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);

        if (is_array($result)) {
            return $result;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from UddoktaPay API.'
        ];
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $executePayment = $this->executePayment();

        if (!isset($executePayment['status']) && !isset($executePayment['metadata']['invoice_id'])) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid Response.',
            ];
        }

        if (isset($executePayment['status']) && $executePayment['status'] === 'COMPLETED') {

            $this->invoiceID = $executePayment['metadata']['invoice_id'];
            $this->setInvoice();

            $existing = $this->checkTransaction($executePayment['transaction_id']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'message'   => 'The transaction has been already used.'
                ];
            }

            if ($executePayment['amount'] < $this->total) {
                return [
                    'status'    => 'error',
                    'message'   => 'You\'ve paid less than amount is required.'
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['transaction_id']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'  => 'success',
                    'message' => 'The payment has been successfully verified.',
                ];
            }
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid Response.',
        ];
    }

    /**
     * Make the transaction V2
     *
     * @return array
     */
    public function makeTransactionV2()
    {
        $executePayment = $this->executePaymentV2();

        if (!isset($executePayment['status']) && !isset($executePayment['metadata']['invoice_id'])) {
            die('Invalid Response');
        }

        $invoiceId = $executePayment['metadata']['invoice_id'];
        $systemUrl = Setting::getValue('SystemURL');
        $url = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;

        if (isset($executePayment['status']) && $executePayment['status'] === 'COMPLETED') {

            $this->invoiceID = $executePayment['metadata']['invoice_id'];
            $this->setInvoice();

            $existing = $this->checkTransaction($executePayment['transaction_id']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'url'       => $url . '&error=The transaction has been already used.',
                ];
            }

            if ($executePayment['amount'] < $this->total) {
                return [
                    'status'    => 'error',
                    'url'       => $url . '&error=You\'ve paid less than amount is required',
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['transaction_id']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'    => 'success',
                    'url'       => $url
                ];
            }
        }

        return [
            'status'    => 'error',
            'url'       => $url . '&error=The payment is pending for verification.',
        ];
    }
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Direct access forbidden.");
}

$UddoktaPay = UddoktaPay::init();


if (!$UddoktaPay->isActive) {
    die("The gateway is unavailable.");
}

if (isset($_POST['invoice_id'])) {
    $response = $UddoktaPay->makeTransactionV2();
    if (isset($response['url'])) {
        header("Location:" . $response['url']);
    }
} else {
    $response = $UddoktaPay->makeTransaction();
    die(json_encode($response));
}

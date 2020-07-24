<?php
/**
 * BoxBilling
 *
 * @copyright BoxBilling, Inc (http://www.boxbilling.com)
 * @license   Apache-2.0
 *
 * Copyright BoxBilling, Inc
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

class Payment_Adapter_SlydePay implements \Box\InjectionAwareInterface
{
    private $config = array();
    const ENDPOINT = "https://app.slydepay.com.gh/api/merchant";
    const REDIRECT_URL =  "https://app.slydepay.com/paylive/detailsnew.aspx?pay_token=";

    const PAYMENT_STATUS_NEW = "NEW";
    const PAYMENT_STATUS_PENDING = "PENDING";
    const PAYMENT_STATUS_CONFIRMED = "CONFIRMED";
    const PAYMENT_STATUS_DISPUTED = "DISPUTED";
    const PAYMENT_STATUS_CANCELLED = "CANCELLED";

    protected $di;

    private $url;

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }
    
    public function __construct($config)
    {
        $this->config = $config;
        
        if(!function_exists('curl_exec')) {
            throw new Payment_Exception('PHP Curl extension must be enabled in order to use Slydepay gateway');
        }
        
        if(!isset($this->config['merchantKey'])) {
            throw new Payment_Exception('Payment gateway "SlydePay" is not configured properly. Please update configuration parameter "SlydePay Merchant Key" at "Configuration -> Payments".');
        }
        if(!isset($this->config['emailOrMobileNumber'])) {
            throw new Payment_Exception('Payment gateway "SlydePay" is not configured properly. Please update configuration parameter "SlydePay Email or Mobile Number" at "Configuration -> Payments".');
        }
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'     =>  false,
            'description'     =>  'Enter your SlydePay Merchant Key to start accepting payments by SlydePay.',
            'description_client'     => '3% Transaction Fee (Slydepay account NOT required).',
            'can_load_in_iframe'    => false,
            'form'  => array(
                'merchantKey' => array('text', array(
                            'label' => 'SlydePay Merchant Key',
                    ),
                 ),
                'emailOrMobileNumber' => array('text', array(
                            'label' => 'SlydePay Email or Mobile Number',
                    ),
                ),
                'charge' => array('text', array(
                            'label' => 'Transaction Charge (%)',
                        ),
                    ),               
            ),
        );
    }

    /**
     * Payment gateway endpoint
     * 
     * @return string
     */
    public function getServiceUrl()
    {
		return $this->url;
    }

    /**
     * Return payment gateway type
     * @return string
     */
    public function getType()
    {
        return Payment_AdapterAbstract::TYPE_HTML;
    }

	/**
	 * @param string $url
	 */
	protected function download($path, $post_vars = array(), $pheaders = array(), $contentType = 'application/x-www-form-urlencoded')
    {
        $post_contents = array();
        $merchantKey = $this->config["merchantKey"];
        $emailOrNumber = $this->config["emailOrMobileNumber"];
        $auth_params = array("emailOrMobileNumber" => "$emailOrNumber","merchantKey" => "$merchantKey");
        
		if ($post_vars) {
            if(is_array($post_vars)){
                $post_contents = array_merge($auth_params, $post_vars);
            }
        }
        else {
            $post_contents = $auth_params;
        }
        
		
		$headers = Array(			
            'Content-Type: '.$contentType,
        );
        
        if (!empty($pheaders)) {
			if (!is_array($pheaders)) {
				$headers[count($headers)] = $pheaders;
			} else {
				$next = count($headers);
				$count = count($pheaders);
				for ($i = 0; $i < $count; $i++) { $headers[$next + $i] = $pheaders[$i]; }
			}
		}
		    
        $url = self::ENDPOINT.$path;

        $ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_contents));

		$data = curl_exec($ch);
      
		if (curl_errno($ch)) return false;
		curl_close($ch);
		return $data;
	}
    

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
        
        $title = $this->getInvoiceTitle($invoice);
        $form  = '<div class="aligncenter">';
          if(isset($this->config['auto_redirect']) && $this->config['auto_redirect'] && isset($this->config['can_load_in_iframe']) && !$this->config['can_load_in_iframe']) {
                    $path = "/invoice/banklink"."/". $invoice->hash ."/". $invoice->gateway_id;
                    $link = $this->di['url']->link($path);
                    $form .= sprintf('<h2>%s</h2>', __('Redirecting to SlidePay Website'));
                    $form .=  '<a href="'.$link.'" class="btn btn-alt btn-primary btn-large"  id="redirect-button">Proceed to SlydePay</a>'. PHP_EOL. PHP_EOL;
                    $form .= "<script type='text/javascript'>
                    $(document).ready(function(){  
                        $('#redirect-button').click(); 
                        
                        });
                         </script>";
                }
              else {
        
        $randN = rand(0,10);
        $uniq_invoice_ref = substr($invoice["hash"], $randN, $randN+8);

        $amount =  $this->getAmountInDefaultCurrency($invoice["currency"], $invoice["total"]);
        
        $fee = 0;
              
        if(is_numeric($this->config["charge"]) && $this->config["charge"] > 0){
            $fee = round(($amount * $this->config["charge"] / 100 ), 2);
        }
    
        
        $data = array(
                    'amount'    => $amount + $fee,
                    'orderCode'  => $uniq_invoice_ref,
                    'description' => $title            
                    );

            try{
                // create invoice at slydepay
                $response = $this->createInvoice($data);

            	if(isset($response->success) && $response->success){
                    $payToken =  $response->result->payToken;

                    $retP = array(
                    'bb_invoice_id' => $invoice["id"],
                    'amount'        => $invoice["total"],
					'bb_redirect' => true,
					'bb_invoice_hash' => $invoice["hash"],
                    );

                    $returnParams =$payToken."&".http_build_query($retP);
                    $url = self::REDIRECT_URL.$returnParams;

                                       
                    $form .= sprintf('<h2>%s</h2>',$title).PHP_EOL;
                    $form .= '<hr>';                    
                    $form .= '<h3>Option 1 - Scan QR Code with SlydePay App to pay.</h3>';                    
                    $form .= '<hr>';
                    if(isset($response->result->qrCodeUrl) && !empty($response->result->qrCodeUrl)){
      
                        $form .= '<div style="float:left">'; 
        // show qr code
        $form .= '<img src="'.$response->result->qrCodeUrl.'" alt="QrCode" title="Scan to make payment">'.  PHP_EOL;
                        $form .= '</div>'.PHP_EOL;     
                    }
                        $form .= '<div style="float: right; width:40%; border: solid 1px orange; padding: 5px;">'.PHP_EOL;
                        $form .= '<table align="right" style="width:100%;"><tbody>'.PHP_EOL;
                        $form .= sprintf("<tr><td>Invoice Amount</td><td>%0.2f</td></tr>",$amount); 
                        
                        if($fee > 0){
                            $charge = $this->config["charge"];
                              $form .= sprintf("<tr><td>Transaction Fee - %0.2f%% </td><td>%0.2f</td></tr>",$charge, $fee);
                            }    
                            $form .= sprintf("<tr><td><strong>Total (".$this->getDefaultCurrency().")</strong></td><td><strong>%0.2f</strong></td></tr>",$amount + $fee);
                            
                        $form .= '</tbody></table>'.PHP_EOL;
                        $form .= '</div>'.PHP_EOL; 
                    $form .= '<div style="clear:both; padding-top:15px;">'.PHP_EOL;
               
                    $imgs = $this->getPaymentOptions();    
        $form .= $imgs;
                    $form .= '<div style="clear:left; padding:15px;">';        
                    $form .=  '<a href="'.$url.'" class="btn btn-alt btn-primary btn-large"  id="payment-button"> Proceed to SlydePay</a>'. PHP_EOL;
                    $form .= '</div>'.PHP_EOL;
                    $form .= '</div>'.PHP_EOL;
                    $form .= '</div>'.PHP_EOL;

                    return $form;

        }

                else if(isset($response->success) && !($response->success)){
                        return "<p>".$response->errorMessage."</p>";
                }
                else {
                return '<h2>Problem connecting to gateway.</h2>'; 
                }    
            }        
        catch (Exception $ex){
            return new \Box_Exception($ex);
            }  
        }        
        }
              
    public function createInvoice($data){
		      $this->di['logger']->info("Creating invoice at Slypepay with description: ".$data["description"]);
        $response = $this->download('/invoice/create', $data, "" ,'application/json');           
                $obj = json_decode($response);
                 $this->di['logger']->info("Invoice created at Slypepay: ".json_encode($response));
        return $obj;
    }


    /**
     * return html string
     */
    public function getPaymentOptions(){
        $response = $this->download('/invoice/payoptions', false, "" ,'application/json');
        $obj = json_decode($response);
        $paymentOptions;
        if(isset($obj->success) &&  $obj->success){
         $paymentOptions = $obj->result; // array of image objects
         }   
         else {
         // TODO: if there was an error, automatically redirect
             $paymentOptions = array();
         }
 
 
        $imgs = '';
        $imgs .= '<h3>Option 2 - Supported Payment Methods</h3>';
        $imgs .= '<hr>';
        if(sizeof($paymentOptions) > 0){
            foreach ($paymentOptions as $option){
                if($option->active){      
                 $imgs .= '<div>';        
                 $imgs .= sprintf('<img src="%s" title="%s" style="margin-right:10px; float:left; height: 60px; width: 60px;"/>',
                 $option->logourl, $option->name);
                 $imgs .= '</div>'.PHP_EOL;  
             }
 
            }
            // $imgs .= $response;
        }
        return $imgs;
    }
    

    public function getInvoiceTitle(array $invoice)
    {
        $p = array(
            ':id'=>sprintf('%05s', $invoice['nr']),
            ':serie'=>$invoice['serie'],
        );
        return __('Payment for invoice :serie:id', $p);
    }

     /**
     * Generate a GUID string
     * code taken from http://php.net/manual/en/function.com-create-guid.php#99425
     * @return string
     */
    private static function generateGUID()
    {
    if (function_exists('com_create_guid') === true)
    {
        return trim(com_create_guid(), '{}');
    }

    return sprintf(
        '%04X%04X-%04X-%04X-%04X-%04X%04X%04X', 
    mt_rand(0, 65535), 
    mt_rand(0, 65535), 
    mt_rand(0, 65535), 
    mt_rand(16384, 20479), 
    mt_rand(32768, 49151), 
    mt_rand(0, 65535), 
    mt_rand(0, 65535), 
    mt_rand(0, 65535));
    }
  
    /**
     * @param $id - transaction id from db
     * @param $data - ipn data
     */
     
    
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {

        $ipn = $data['get']; // slydepay returns get parameters no post

        $status = $this->_getTransactionStatusGtw($ipn["status"]);

        if ($status == "pending") {
            $invoice = $api_admin->invoice_get(array('id' => $ipn['bb_invoice_id']));
            $tx = $api_admin->invoice_transaction_get(array('id' => $id));

            $this->di['logger']->info("Processing transaction from Slypepay with description: " . $data["description"]);

            if (!$tx['status']) {
                $api_admin->invoice_transaction_update(array('id' => $id, 'status' => $status));
            }

            if (!$tx['invoice_id']) {
                $inv_id = isset($ipn['bb_invoice_id']) ? $ipn['bb_invoice_id'] : $invoice["id"];
                $api_admin->invoice_transaction_update(array('id' => $id, 'invoice_id' => $inv_id));
            }

            if (!$tx['amount']) {
                $amount = isset($ipn['amount']) ? $ipn['amount'] : $invoice["total"];
                $api_admin->invoice_transaction_update(array('id' => $id, 'amount' => $amount));
            }

            if (!$tx['currency']) {
                $api_admin->invoice_transaction_update(array('id' => $id, 'currency' => $invoice["currency"]));
            }

            if (!$tx['txn_id']) {
                $api_admin->invoice_transaction_update(array('id' => $id, 'txn_id' => $ipn['transac_id']));
            }

            if (!$tx['type']) {
                $api_admin->invoice_transaction_update(array('id' => $id, 'type' => \Payment_Transaction::TXTYPE_PAYMENT));
            }

            $this->checkPaymentStatus($api_admin, $id, $data);

            $d = array(
                'id' => $id,
                'error' => '',
                'error_code' => '',
                'updated_at' => date('Y-m-d H:i:s'),
            );

        }

        // $api_admin->invoice_transaction_update($d);
    }


    private function _getTransactionStatusGtw($status){
        switch($status){
            case 0:
                return 'pending';
            break;
            case -1:
                return 'error';
            break;
            case -2:
                return 'cancelled';
            break;
            default:
                return "unknown";
            break;
        
        }
    }
	
	public function checkPaymentStatus($api_admin, $txn_id, $ipn){
        $data = array(
            'payToken'           => $ipn['get']["pay_token"],
            'orderCode'          => $ipn['get']["cust_ref"],
            'confirmTransaction' => false            
            );
        
        $response = $this->download('/invoice/checkstatus', $data, "" ,'application/json');
        $obj = json_decode($response);
        $status = 'unknown';
        if(isset($obj->success) &&  $obj->success){
            $status = $obj->result;
            $d = array(
                'id'        => $txn_id, 
                'txn_status'    => $status,
                'error'     => '',
                'error_code'=> '', 
                'updated_at'=> date('Y-m-d H:i:s'),
            );
            $api_admin->invoice_transaction_update($d);
        }
         else if(isset($obj->success) &&  !($obj->success)) {
        
            $d = array(
                'id'         => $txn_id, 
                'error'      => $obj->errorMessage,
                'error_code' => $obj->errorCode,
                'txn_status' => $obj->result,
                'updated_at' => date('Y-m-d H:i:s'),
                );

            $api_admin->invoice_transaction_update($d);
        
        }
        
         return $status;
        
    }    

    public function confirmTransaction($api_admin, $txn_id, $ipn){
                     
        $this->checkPaymentStatus($api_admin,  $txn_id, $ipn);
        $tx = $api_admin->invoice_transaction_get(array('id'=>$txn_id)); 
        
        if ( strcasecmp($tx["txn_status"], self::PAYMENT_STATUS_CANCELLED) == 0) {
            return;
        }

        if (strcasecmp($tx["txn_status"], self::PAYMENT_STATUS_CONFIRMED) == 0 || strcasecmp($tx["txn_status"], self::PAYMENT_STATUS_PENDING) == 0 ) {


            $data = array(
             'payToken'           => $ipn['get']["pay_token"],
             'confirmTransaction' => true            
             );
 
         $response = $this->download('/transaction/confirm', $data, "" ,'application/json');
         $obj = json_decode($response);

         //$this->di['logger']->info();

         if(isset($obj->success) &&  $obj->success){
            
             $d = array(
                 'id'        => $txn_id,
                 'error'     => '',
                 'error_code'=> '', 
                 'status'    => 'processed',
                 'updated_at'=> date('Y-m-d H:i:s'),
             );
             
             $api_admin->invoice_transaction_update($d);
             $this->checkPaymentStatus($api_admin, $txn_id, $ipn);
           
          }   
          else if(isset($obj->success) &&  !($obj->success)){
          $d = array(
             'id'        => $txn_id, 
             'error'     => $obj->errorMessage,
             'error_code'=> $obj->errorCode,
             'txn_status'    => $obj->result,
             'updated_at'=> date('Y-m-d H:i:s'),
         );

         $api_admin->invoice_transaction_update($d);
 
          }

         }

       
        }
        
     public function cancelTransaction($api_admin, $txn_id, $ipn){
                     
        $this->checkPaymentStatus($api_admin,  $txn_id, $ipn);
        $tx = $api_admin->invoice_transaction_get(array('id'=>$txn_id)); 
        
        if(strcasecmp($tx["txn_status"], self::PAYMENT_STATUS_CANCELLED) == 0 || strcasecmp($tx["txn_status"], self::PAYMENT_STATUS_NEW) == 0){
            return;
        }


        if($tx["txn_status"] == "pending"){ 

         }
    

            $data = array(
             'payToken'           => $ipn['get']["pay_token"],
             'confirmTransaction' => true            
            );
 
         $response = $this->download('/transaction/confirm', $data, "" ,'application/json');
         $obj = json_decode($response);

         //$this->di['logger']->info($response);

         if(isset($obj->success) &&  $obj->success){
        
        $d = array(
                 'id'        => $txn_id,
            'error'     => '',
            'error_code'=> '',
            'status'    => 'processed',
            'updated_at'=> date('Y-m-d H:i:s'),
        );
             
             $api_admin->invoice_transaction_update($d);
           
          }   
          else if(isset($obj->success) &&  !($obj->success)){
          $d = array(
             'id'        => $txn_id, 
             'error'     => $obj->errorMessage,
             'error_code'=> $obj->errorCode,
             'txn_status'    => $obj->result,
             'updated_at'=> date('Y-m-d H:i:s'),
         );

        $api_admin->invoice_transaction_update($d);
 
    }
    }
    
    /**
     * Generate links for performing actions required by gateway
     */
    public function getActions(){
       return array(
           array(
               'name'   => 'status',
               'label'  => 'Check Payment Status'
                ) ,
           array(
               'name'   => 'confirm',
               'label'  => 'Confirm Transaction'            
                ),
           array(
               'name'   => 'cancel',
               'label'  => 'Cancel Transaction'
                ) 
            );
    }

    /**
     * process actions to be performed by gateway
     */
    public function processAction($api_admin, $txn_id, $ipn, $gateway_id, $action){
        
        switch($action){
            case "confirm":
               return $this->confirmTransaction($api_admin, $txn_id, $ipn);
            break;
            case "cancel":
               return;
            case "status":
                return $this->checkPaymentStatus($api_admin, $txn_id, $ipn);               
            default:
            return;
            
        }
    }

    protected function getAmountInDefaultCurrency($currency, $amount){
      
        $currencyService = $this->di['mod_service']('currency');
        return $currencyService->toBaseCurrency($currency, $amount);

    }
   
    protected function getDefaultCurrency(){
        $currencyService = $this->di['mod_service']('currency');
        $default = $currencyService->getDefault();
        return $default->code;
    }
}
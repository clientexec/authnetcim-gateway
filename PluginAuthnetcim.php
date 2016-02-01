<?php
require_once('AuthnetCIM.class.php');
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';

/**
* @package Plugins
*/
class PluginAuthnetcim extends GatewayPlugin
{
    function getVariables()
    {
        $variables = array (
            lang('Plugin Name') => array (
                'type'          => 'hidden',
                'description'   => lang('How CE sees this plugin ( not to be confused with the Signup Name )'),
                'value'         => 'Authorize.Net CIM'
            ),
            lang('Authorize.Net CIM API Login ID') => array (
                'type'          => 'password',
                'description'   => lang('Please enter your Authorize.Net CIM API Login ID here.'),
                'value'         => ''
            ),
            lang('Authorize.Net CIM Transaction Key') => array (
                'type'          => 'password',
                'description'   => lang('Please enter your Authorize.Net CIM Transaction Key here.'),
                'value'         => ''
            ),
            lang('Authorize.Net CIM Validation Mode') => array (
                'type'          => 'options',
                'description'   => lang('Indicates the processing mode for the request.'),
                'options'       => array(
                    'liveMode'  => lang('Live Mode'),
                    'testMode'  => lang('Test Mode')
                )
            ),
            lang('Authorize.Net CIM Test Mode') => array (
                'type'          => 'yesno',
                'description'   => lang('Select YES if you want to use Authorize.Net CIM testing server, so no actual monetary transactions are made.'),
                'value'         => '0'
            ),
            lang('Invoice After Signup') => array (
                'type'          => 'yesno',
                'description'   => lang('Select YES if you want an invoice sent to the customer after signup is complete.'),
                'value'         => '1'
            ),
            lang('Signup Name') => array (
                'type'          => 'text',
                'description'   => lang('Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card.'),
                'value'         => 'Authorize.Net CIM'
            ),
            lang('Auto Payment') => array (
                'type'          => 'hidden',
                'description'   => lang('No description'),
                'value'         => '1'
            ),
            lang('Dummy Plugin') => array (
                'type'          => 'hidden',
                'description'   => lang('1 = Only used to specify a billing type for a customer. 0 = full fledged plugin requiring complete functions'),
                'value'         => '0'
            )
        );
        return $variables;
    }

    function credit($params)
    {
        $params['refund'] = true;
        return $this->autopayment($params);
    }

    function singlepayment($params)
    {
        return $this->autopayment($params);
    }

    function autopayment($params)
    {
        $cPlugin = new Plugin($params['invoiceNumber'], "authnetcim", $this->user);
        $cPlugin->setAmount($params['invoiceTotal']);

        if (isset($params['refund']) && $params['refund']) {
            $isRefund = true;
            $cPlugin->setAction('refund');
        }else{
            $isRefund = false;
            $cPlugin->setAction('charge');
        }

        // The idea was to redirect the customer to create his profile in Authorize.net CIM
        // However, it is causing more troubles than anything else:
        // - The redirection is done making use of an error posted in the step 3
        // - After the redirection, the customer can fill his profile, and then go back to order complete, but will be really without doing the send_account_creation_email
        // - At the end, the invoice continues unpaid
        // So, I have found it is better to try to charge the customer even when not having an Authorize.net CIM account.
        // It leads him to his invoice and with a more clear error message that will lead him to create his Authorize.net CIM account, etc.
        /*
        if($params['isSignup']){
            return $this->ShowURL($params);
        }
        */

        //Create customer Authnet CIM profile transaction
        $customerProfile = $this->createCustomerProfileTransaction($params, $isRefund);
        if($customerProfile['error']){
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation. ".$customerProfile['detail']));
            return $this->user->lang("There was an error performing this operation. ".$customerProfile['detail']);
        }else{
            if($isRefund){
                $cPlugin->PaymentAccepted($customerProfile['amount'], "Authorize.Net CIM refund of {$customerProfile['amount']} was successfully processed.", $customerProfile['transaction_ID']);
                return array('AMOUNT' => $customerProfile['amount']);
            }else{
                $cPlugin->setTransactionID($customerProfile['transaction_ID']);
                $cPlugin->PaymentAccepted($customerProfile['amount'], "Authorize.Net CIM payment of {$customerProfile['amount']} was accepted. Approval code: {$customerProfile['approval_code']}", $customerProfile['transaction_ID']);
                return '';
            }
        }
    }

    // Create customer Authnet CIM profile
    function createFullCustomerProfile($params)
    {
        $customerProfile = $this->createCustomerProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        $customerProfile = $this->createCustomerPaymentProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        $customerProfile = $this->createCustomerShippingAddress($params);

        return $customerProfile;
    }

    // Create customer Authnet CIM profile
    function createCustomerProfile($params)
    {
        //Customer Information from CE
        $email_address = $params['userEmail'];
        $description   = $params['userLastName'].' '.$params['userFirstName'];
        $customer_id   = $params['userID'];

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        // Create the profile
        try{
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('email', $email_address);
            $cim->setParameter('description', $description);
            $cim->setParameter('merchantCustomerId', $customer_id);
            $cim->createCustomerProfile();

            // Get the profile ID returned from the request. Also if fails because of a duplicate record already exists.
            if($cim->isSuccessful() || $cim->getCode() == 'E00039'){
                $user = new User($params['CustomerID']);
                $user->updateCustomTag('Billing-Profile-ID', serialize(array('authnetcim' => $cim->getProfileID())));
                $user->save();

                return array(
                    'error'               => false,
                    'profile_id'          => $cim->getProfileID(),
                    'payment_profile_id'  => 0,
                    'shipping_profile_id' => 0
                );
            }else{
                return array(
                    'error'  => true,
                    'detail' => $cim->getResponseSummary()
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }
    }

    // Create customer Authnet CIM payment profile
    function createCustomerPaymentProfile($params)
    {
        // Get customer Authnet CIM profile
        $customerProfile = $this->getCustomerProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        try{
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('customerProfileId', $customerProfile['profile_id']);
            if($params['userFirstName'] != '') $cim->setParameter('billToFirstName', $params['userFirstName']);
            if($params['userLastName'] != '') $cim->setParameter('billToLastName', $params['userLastName']);
            if($params['userOrganization'] != '') $cim->setParameter('billToCompany', $params['userOrganization']);
            if($params['userAddress'] != '') $cim->setParameter('billToAddress', $params['userAddress']);
            if($params['userCity'] != '') $cim->setParameter('billToCity', $params['userCity']);
            if($params['userState'] != '') $cim->setParameter('billToState', $params['userState']);
            if($params['userZipcode'] != '') $cim->setParameter('billToZip', $params['userZipcode']);
            if($params['userCountry'] != '') $cim->setParameter('billToCountry', $params['userCountry']);
            if($params['userPhone'] != '') $cim->setParameter('billToPhoneNumber', $params['userPhone']);
            if($params['userPhone'] != '') $cim->setParameter('billToFaxNumber', $params['userPhone']);
            if($params['userCCNumber'] != '') $cim->setParameter('cardNumber', $params['userCCNumber']);
            if($params['cc_exp_year'] != '' && $params['cc_exp_month'] != '') $cim->setParameter('expirationDate', $params['cc_exp_year'].'-'.$params['cc_exp_month']);
            $cim->createCustomerPaymentProfile();

            if($cim->isSuccessful() || $cim->getCode() == 'E00039'){
                return array(
                    'error'               => false,
                    'profile_id'          => $cim->getProfileID(),
                    'payment_profile_id'  => $cim->getPaymentProfileId(),
                    'shipping_profile_id' => $cim->getCustomerAddressId()
                );
            }else{
                return array(
                    'error'  => true,
                    'detail' => $cim->getResponseSummary()
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }
    }

    // Create customer Authnet CIM shipping address
    function createCustomerShippingAddress($params)
    {
        // Get customer Authnet CIM profile
        $customerProfile = $this->getCustomerProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        try{
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('customerProfileId', $customerProfile['profile_id']);
            if($params['userFirstName'] != '') $cim->setParameter('shipToFirstName', $params['userFirstName']);
            if($params['userLastName'] != '') $cim->setParameter('shipToLastName', $params['userLastName']);
            if($params['userOrganization'] != '') $cim->setParameter('shipToCompany', $params['userOrganization']);
            if($params['userAddress'] != '') $cim->setParameter('shipToAddress', $params['userAddress']);
            if($params['userCity'] != '') $cim->setParameter('shipToCity', $params['userCity']);
            if($params['userState'] != '') $cim->setParameter('shipToState', $params['userState']);
            if($params['userZipcode'] != '') $cim->setParameter('shipToZip', $params['userZipcode']);
            if($params['userCountry'] != '') $cim->setParameter('shipToCountry', $params['userCountry']);
            if($params['userPhone'] != '') $cim->setParameter('shipToPhoneNumber', $params['userPhone']);
            if($params['userPhone'] != '') $cim->setParameter('shipToFaxNumber', $params['userPhone']);
            $cim->createCustomerShippingAddress();

            if($cim->isSuccessful() || $cim->getCode() == 'E00039'){
                return array(
                    'error'               => false,
                    'profile_id'          => $cim->getProfileID(),
                    'payment_profile_id'  => $cim->getPaymentProfileId(),
                    'shipping_profile_id' => $cim->getCustomerAddressId()
                );
            }else{
                return array(
                    'error'  => true,
                    'detail' => $cim->getResponseSummary()
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }
    }

    //Get customer Authnet CIM profile
    function getCustomerProfile($params)
    {
        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        $profile_id == '';
        $Billing_Profile_ID = '';
        $user = new User($params['CustomerID']);
        if($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != ''){
            $profile_id_array = unserialize($Billing_Profile_ID);
            if(is_array($profile_id_array) && isset($profile_id_array['authnetcim'])){
                $profile_id = $profile_id_array['authnetcim'];
            }
        }

        if($profile_id == ''){
            // Create or get customer Authnet CIM profile
            $customerProfile = $this->createCustomerProfile($params);
            if($customerProfile['error']){
                return $customerProfile;
            }else{
                $profile_id = $customerProfile['profile_id'];
            }
        }

        try{
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('customerProfileId', $profile_id);
            $cim->getCustomerProfile();
            if($cim->isSuccessful()){

                $user = new User($params['CustomerID']);
                $user->updateCustomTag('Billing-Profile-ID', serialize(array('authnetcim' => $cim->getProfileID())));
                $user->save();

                return array(
                    'error'               => false,
                    'profile_id'          => $cim->getProfileID(),
                    'payment_profile_id'  => $cim->getPaymentProfileId(),
                    'shipping_profile_id' => $cim->getCustomerAddressId()
                );
            }else{
                return array(
                    'error'  => true,
                    'detail' => $cim->getResponseSummary()
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }

    }

    //Validate customer Authnet CIM payment profile
    function validateCustomerPaymentProfile($params)
    {
        //Get customer Authnet CIM profile
        $customerProfile = $this->getCustomerProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        $validationMode = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Validation Mode');
        if($validationMode == ''){
            $validationMode = 'liveMode';
        }

        try{
            //Validate customer payment profile
            if($customerProfile['profile_id'] != '' && $customerProfile['payment_profile_id'] != '' && $customerProfile['shipping_profile_id'] != ''){
                $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
                $cim->setParameter('customerProfileId', $customerProfile['profile_id']);
                $cim->setParameter('customerPaymentProfileId', $customerProfile['payment_profile_id']);
                $cim->setParameter('customerShippingAddressId', $customerProfile['shipping_profile_id']);
                $cim->setParameter('validationMode', $validationMode);
                $cim->validateCustomerPaymentProfile();
                if($cim->isSuccessful()){
                    return $customerProfile;
                }else{
                    return array(
                        'error'  => true,
                        'detail' => $cim->getResponseSummary()
                    );
                }
            }else{
                return array(
                    'error'  => true,
                    'detail' => 'The customer do not have a customer Authnet CIM payment profile or shipping profile'
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }
    }

    //Create customer Authnet CIM profile transaction
    function createCustomerProfileTransaction($params, $isRefund)
    {
        //Validate customer Authnet CIM payment profile
        $customerProfile = $this->validateCustomerPaymentProfile($params);
        if($customerProfile['error']){
            return $customerProfile;
        }

        //Invoice Information from CE
        $amount = sprintf("%01.2f", round($params["invoiceTotal"], 2));
        $purchase_invoice_id = $params['invoiceNumber'];

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        try{
            // Process the transaction
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('customerProfileId', $customerProfile['profile_id']);
            $cim->setParameter('customerPaymentProfileId', $customerProfile['payment_profile_id']);
            $cim->setParameter('customerShippingAddressId', $customerProfile['shipping_profile_id']);
            $cim->setParameter('amount', $amount);

            if($isRefund){
                $cim->setParameter('transId', $params['invoiceRefundTransactionId']);
                $cim->createCustomerProfileTransaction('profileTransRefund');
            }else{
                $cim->setParameter('orderInvoiceNumber', true);
                $cim->setParameter('invoiceNumber', $purchase_invoice_id);
                $cim->setParameter('description', 'Invoice '.$purchase_invoice_id);
                $cim->createCustomerProfileTransaction('profileTransAuthCapture');
            }

            // Get the payment or refund profile ID returned from the request
            $approval_code = '';
            $transaction_ID = '';
            if($cim->isSuccessful()){
                    return array(
                        'error'          => false,
                        'approval_code'  => $cim->getAuthCode(),
                        'transaction_ID' => $cim->getTransactionID(),
                        'amount'         => $amount
                    );
            }else{
                return array(
                    'error'  => true,
                    'detail' => $cim->getResponseSummary()
                );
            }
        }catch(AuthnetCIMException $e){
            return array(
                'error'  => true,
                'detail' => $e
            );
        }
    }

    function ShowURL($params)
    {
        $tempUser = new User($params['CustomerID']);

        //Customer Information from CE
        $params['userID']        = "CE" . $tempUser->getId();
        $params['userEmail']     = $tempUser->getEmail();
        $params['userFirstName'] = $tempUser->getFirstName();
        $params['userLastName']  = $tempUser->getLastName();

        // Get customer Authnet CIM profile
        $customerProfile = $this->getCustomerProfile($params);
        if($customerProfile['error']){
            return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
                <html xmlns="http://www.w3.org/1999/xhtml">
                    <head>
                        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                        <title>Untitled Document</title>
                    </head>
                    <body>'.$customerProfile['detail'].'</body>
                </html>';
        }

        //Authorize.net CIM Credentials from CE plugin
        $myapilogin = $this->settings->get('plugin_authnetcim_Authorize.Net CIM API Login ID');
        $mYtRaNsaCTiOnKEy = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Transaction Key');
        $sandbox = $this->settings->get('plugin_authnetcim_Authorize.Net CIM Test Mode');
        $USE_DEVELOPMENT_SERVER = ($sandbox)? AuthnetCIM::USE_DEVELOPMENT_SERVER : AuthnetCIM::USE_PRODUCTION_SERVER;

        //Need to check to see if user is coming from signup
        if ($params['isSignup']) {
            // Actually handle the signup URL setting
            if($this->settings->get('Signup Completion URL') != '') {

                $returnURL = $this->settings->get('Signup Completion URL'). '?success=1';
            }else{
                $returnURL = $params["clientExecURL"]."/order.php?step=complete&pass=1";
            }
            $hosted_Profile_Page_Border_Visible = 'true';
        }else {
            $hosted_Profile_Page_Border_Visible = 'false';
            $returnURL = $params['returnURL'];
        }


        // Get the Hosted Profile Page
        $hosted_Profile_Return_Url_Text = 'Continue to confirmation page.';
        $hosted_Profile_Card_Code_Required = 'true';
        $hosted_Profile_Billing_Address_Required = 'true';

        try{
            $cim = new AuthnetCIM($myapilogin, $mYtRaNsaCTiOnKEy, $USE_DEVELOPMENT_SERVER);
            $cim->setParameter('customerProfileId', $customerProfile['profile_id']);
            if ($params['isSignup']) {
                $cim->setParameter('hostedProfileReturnUrl', $returnURL);
            }else{
                $cim->setParameter('hostedProfileIFrameCommunicatorUrl', $returnURL);
            }
            $cim->setParameter('hostedProfileReturnUrlText', $hosted_Profile_Return_Url_Text);
            $cim->setParameter('hostedProfilePageBorderVisible', $hosted_Profile_Page_Border_Visible);
            $cim->setParameter('hostedProfileCardCodeRequired', $hosted_Profile_Card_Code_Required);
            $cim->setParameter('hostedProfileBillingAddressRequired', $hosted_Profile_Billing_Address_Required);
            $cim->getHostedProfilePage();

            // Get the token for the profile
            if($cim->isSuccessful()){
                $profile_token = $cim->getProfileToken();
                return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
                    <html xmlns="http://www.w3.org/1999/xhtml">
                        <body>
                            <form id="formAuthorizeNetPage" method="post" action="https://'.(($sandbox)? 'test' : 'secure').'.authorize.net/profile/manage">
                                <input type="hidden" name="token" value="'.$profile_token.'"/>
                            </form>
                            <script type="text/javascript">
                                document.getElementById("formAuthorizeNetPage").submit();
                            </script>
                        </body>
                    </html>';
            }else{
                return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
                    <html xmlns="http://www.w3.org/1999/xhtml">
                        <head>
                            <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                            <title>Untitled Document</title>
                        </head>
                        <body>'.$cim->getResponseSummary().'</body>
                    </html>';
            }
        }catch(AuthnetCIMException $e){
            return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
                <html xmlns="http://www.w3.org/1999/xhtml">
                    <head>
                        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
                        <title>Untitled Document</title>
                    </head>
                    <body>'.$e.'</body>
                </html>';
        }
    }
}
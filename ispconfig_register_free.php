<?php 
// Prevent loading this file directly
defined( 'ABSPATH' ) || exit; 

/**
 * Nordhosting TK - Register a free user through ISPConfig SOAP with including subdomain and shell account
 */
class IspconfigRegisterFree extends IspconfigRegister {
    public static $Self;
    
    public static $TemplateID = 4;
    public static $DefaultDomain = 'yourdomain.tld';
    
    public $forbiddenUserEx;
    
    // product example using Client limit tempaltes from ISPconfig
    public $products = [
        '4' => ['name' => '300MB Free Webspace'],
        '1' => ['name' => '5GB Webspace'],
        '2' => ['name' => '10GB Webspace'],
        '3' => ['name' => '30GB Webspace']
    ];
    
    public static function init() {
        if(!self::$Self) self::$Self = new self();
    }
    
    public function __construct(){
        parent::__construct();
        
        // support for shortcode using "[ispconfig class=IspconfigRegisterFree ...]"
        $this->withShortcode();
        // used to active ajax request for this plugin
        $this->withAjax();
        // enable SOAP requests for ISPconfig
        $this->withSoap();
        
        // contains any of the below word is forbidden in username
        $this->forbiddenUserEx = 'www|mail|ftp|smtp|imap|download|upload|image|service|offline|online|admin|root|username|webmail|blog|help|support';
        // exact words forbidden in username
        $this->forbiddenUserEx .= '|^kb$|^wiki$|^api$|^static$|^dev$|^mysql$|^search$|^media$|^status$';
        // start with words forbidden in username
        $this->forbiddenUserEx .= '|^mobile';
    }
    
    /**
     * Called when user submits the data from register form - see IspconfigRegister::Display() for more details
     */
    protected function onPost(){
        if ( 'POST' !== $_SERVER[ 'REQUEST_METHOD' ] ) return;
        
        try{
            if(!$this->onCaptchaPost()) throw new Exception("Wrong or invalid captcha");
            
            $client = $this->validateName($_POST['client']);
            $username = $this->validateUsername( $_POST['username'] );
            $email = $this->validateMail($_POST['email'], $_POST['email_confirm']);
            
            // check the domain part
            if(intval($_POST['product_id']) < 4)
                $domain = $this->validateDomain($_POST['domain']);
            else if(intval($_POST['product_id']) == 4)
                // add the first page for the customer
                $domain = $username . '.'. self::$DefaultDomain;
            
            // check password
            $this->validatePassword($_POST['password'], $_POST['password_confirm']);
            
            $this->session_id = $this->soap->login( WPISPConfig3::$OPTIONS['soapusername'], WPISPConfig3::$OPTIONS['soappassword']);
            
            // fetch all templates from ISPConfig 
            $limitTemplates = $this->GetClientTemplates();
            // filter for only the TemplateID defined in self::$TemplateID
            $limitTemplates = array_filter($limitTemplates, function($v, $k) { return (self::$TemplateID == $v['template_id']); }, ARRAY_FILTER_USE_BOTH);
            
            if(empty($limitTemplates)) throw new Exception("No client template found with ID '{$this->TemplateID}'");
            $foundTemplate = array_pop($limitTemplates);
            
            $opt = ['company_name' => $_POST['company'], 
                    'contact_name' => $client,
                    'domain' => $domain,
                    'street' => $_POST['street'],
                    'zip' => $_POST['zip'],
                    'city' => $_POST['city'],
                    'email' => $email,
                    'username' => $username,
                    'password' => $_POST['password']
            ];
            
            $this->GetClientByUser($opt['username']);
            
            if(!empty($this->client)) throw new Exception('The user you have entered already exists');
            
            // add the customer
            $this->AddClient($opt);
            
            $this->AddWebsite( ['domain' => $opt['domain'], 'password' => $_POST['password'],  'hd_quota' => $foundTemplate['limit_web_quota'], 'traffic_quota' => $foundTemplate['limit_traffic_quota'] ] );
            
            // give the free user a shell
            $this->AddShell(['username' => $opt['username'] . '_shell', 'username_prefix' => $opt['username'] . '_', 'password' => $_POST['password'] ] );
            
            // Logout from ISPconfig
            $this->soap->logout($this->session_id);
            
            echo "<div class='ispconfig-msg ispconfig-msg-success'>Your account '".$opt['username']."' has been created!</div>";
            
            // send confirmation mail
            if(!empty(WPISPConfig3::$OPTIONS['confirm'])) {
                $sent = $this->SendConfirmation($opt);
                if($sent) echo "<div class='ispconfig-msg ispconfig-msg-success'>Confirmation sent</div>";
            }

            echo "<div class='ispconfig-msg'>The registration was successful - click <a href=\"https://".$_SERVER['HTTP_HOST'].":8080/\">here</a> to login</div>";
            
        } catch (SoapFault $e) {
            //echo $this->soap->__getLastResponse();
            echo '<div class="ispconfig-msg ispconfig-msg-error">SOAP Error: '.$e->getMessage() .'</div>';
        } catch (Exception $e) {
            echo '<div class="ispconfig-msg ispconfig-msg-error">'.$e->getMessage() . "</div>";
            $_POST['password'] = $_POST['password_confirm'] = '';
        }
    }
    
    protected function getField($name, $title, $type = 'text', $mandatory = false, $additionalParams = []){
        if(empty($type)) $type = 'text';
        $req = ($mandatory)?'<span style="color: red;"> *</span>':'';
        $label = '<label>'. __( $title ) . $req .'</label>';
        
        $input = '<input type="'.$type.'" class="regular-text" name="'.$name.'" value="'.WPISPConfig3::$OPTIONS[$name].'"';
        foreach($additionalParams as $k => $v) {
            $input .= $k .'="' . $v . '"';
        }
        $input .= ' />';
         
        return '<div style="display:inline-block;margin-left: 0.3em;">'. $label . $input .'</div>';
    }
    
    /**
     * Display the formular and submit button
     * Usually called through shortcode
     */
    public function Display($opt = null){
        $defaultOptions = ['title' => 'WP-ISPConfig3', 'button' => 'Click to create Client', 'subtitle' => 'New Client (incl. Website and Domain)','showtitle' => true];
        
        if(is_array($opt))
            $opt = array_merge($defaultOptions, $opt);
        else 
            $opt = $defaultOptions;
        
        $this->registerAjax();  
        ?>
        <div class="wrap">
            <h2><?php if($opt['showtitle']) _e( $opt['title'], 'wp-ispconfig3' ); ?></h2>
            <?php 
                $this->onPost();
                $cfg = WPISPConfig3::$OPTIONS;
            ?>
            <form method="post" class="ispconfig" action="<?php echo get_permalink() ?>">
            <div id="poststuff" class="metabox-holder has-right-sidebar">
                <div id="post-body">
                    <div id="post-body-content">
                        <div id="normal-sortables" class="meta-box-sortables ui-sortable">
                            <div class="postbox inside">
                                <h3><?php _e( $opt['subtitle'], 'wp-ispconfig3' );?></h3>
                                <div class="inside">
                                    <div style="margin-left: 0.3em;margin-bottom: 0.5em;font-weight: bold;">
                                        <label>Product: </label>
                                        <select name="product_id" data-ispconfig-selectproduct style="width: 340px;">
                                            <?php
                                                foreach($this->products as $k => $v) {
                                                    $s = (isset($_GET['product']) && $_GET['product'] == $k)?'selected':'';
                                                    echo '<option value="'.$k.'" '.$s.'>'.$v['name'].'</option>';
                                                }
                                                
                                                    
                                            ?>
                                        </select>
                                    </div>
                                    <div id="domain" style="margin-left: 0.3em;margin-bottom: 0.5em;font-weight: bold;">
                                        <label>Domain: <span style="color: red;"> *</span></label><input type="text" data-ispconfig-checkdomain class="regular-text" style="width: 340px" name="domain" value="">
                                    </div>
                                    <div id="domainMessage" class="ispconfig-msg" style="display:none;"></div>
                                    <div id="subdomain" style="margin-left: 0.3em;margin-bottom: 0.5em;font-weight: bold;">
                                        <label>Subdomain:</label><span style="font-weight: normal;">http://</span><span id="domain_part">username</span><span style="font-weight: normal;">.<?php echo self::$DefaultDomain ?></span>
                                    </div>
                                    <div>
                                    <?php 
                                        echo $this->getField('client', 'Full name:',null, true);
                                        echo $this->getField('company', 'Company:');
                                        echo $this->getField('street', 'Street', null, true) . "<div style='height:1px'>&nbsp;</div>";
                                        echo $this->getField('zipcode', 'Postal code', null, false);
                                        echo $this->getField('city', 'City', null, true);
                                        echo $this->getField('email', 'e-Mail:', null, true);
                                        echo $this->getField('email_confirm', 'e-Mail confirm:', null, true);
                                        echo $this->getField('username', 'Username:', null, true, ['maxLength' => 20, 'data-ispconfig-subdomain' => '1']) . "<div style='height:1px'>&nbsp;</div>";
                                        echo $this->getField('password', 'Password:', 'password', true);
                                        echo $this->getField('password_confirm', 'Password confirm:', 'password', true);
                                    ?>
                                </div>
                                <div>&nbsp;</div>
                                <?php $this->Captcha() ?>
                                <p style="text-align: right"><input type="submit" class="button-primary" name="submit" value="<?php _e($opt['button']);?>" /></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </form>

            </div>
        <?php
    }
}
?>
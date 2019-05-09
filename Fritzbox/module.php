<?

require_once(__dir__.'/../libs/fritzbox_api.class.php');

class Fritzbox extends IPSModule
{
        
    public function Create()
    {
        parent::Create();
        
        // Public properties
        $this->RegisterPropertyString("FBX_IP", "fritz.box");
        $this->RegisterPropertyString("FBX_USERNAME", "user@user.com");
        $this->RegisterPropertyString("FBX_PASSWORD", "");
        $this->RegisterPropertyInteger("FBX_CALLLIST_TIMELIMIT", 0);
        $this->RegisterPropertyString("FBX_CALLLIST_COLUMNS", "Icon, Date, Name, Caller");
        $this->RegisterPropertyString("FBX_CALLLIST_OUTPUT", "local");
        $this->RegisterPropertyString("FBX_CALLLIST_REVERSESEARCH", "none");
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_1", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_2", true);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_3", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_4", true);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_5", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_6", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_9", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_10", false);
        $this->RegisterPropertyInteger("FBX_CALLLIST_CALLTYPE_11", false);
        $this->RegisterPropertyInteger("FBX_WIFI_GUEST_PASSPHRASE_LENGTH", 10);
        $this->RegisterPropertyString("FBX_DIAL_PORT", "");

        // Private properties
        $this->RegisterPropertyInteger("FBX_LASTPROCESSEDITEM", 0);
        $this->RegisterPropertyInteger("FBX_UPDATE_INTERVAL", 60000);
        $this->RegisterPropertyString("FBX_HOOKNAME", "FritzboxGetMessage"); 
        $this->RegisterPropertyString("FBX_REVERSE_CACHE", "[]");

        // Setting timers
        $this->RegisterTimer('timer_update', IPS_GetProperty($this->InstanceID, 'FBX_UPDATE_INTERVAL'), 'FBX_Update($_IPS[\'TARGET\']);');
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        // register hook
        $sid = $this->RegisterScript("Hook", "Hook", "<? //Do not delete or modify.\ninclude(IPS_GetKernelDirEx().\"scripts/__ipsmodule.inc.php\");\ninclude(\"../modules/SymconFritzbox/Fritzbox/module.php\");\n(new Fritzbox(".$this->InstanceID."))->GetMessageAsBinary();");
        $this->RegisterHook("/hook/".IPS_GetProperty($this->InstanceID, "FBX_HOOKNAME"), $sid);
        IPS_SetHidden($sid, true);

        // Start create profiles
        $this->RegisterProfileBooleanEx("FBX.InternetState", "Internet", "", "", Array(  
                                                                                        Array(false, "Getrennt",  "", 0xFF0000),
                                                                                        Array(true,  "Verbunden", "", 0x00FF00)
                                                                                      ));
        
        $this->RegisterProfileBooleanEx("FBX.WLAN", "Network", "", "", Array(  
                                                                                        Array(false, "Aus",  "", 0xFF0000),
                                                                                        Array(true,  "Ein", "", 0x00FF00)
                                                                                      ));

        $this->RegisterProfileBooleanEx("FBX.Restart", "Power", "", "", Array(  
                                                                                        Array(false, "Nein",  "", 0xFF0000),
                                                                                        Array(true,  "Ja", "", 0x00FF00)
                                                                                      ));
        
        $this->RegisterProfileBooleanEx("FBX.Reconnect", "Power", "", "", Array(  
                                                                                        Array(false, "Nein",  "", 0xFF0000),
                                                                                        Array(true,  "Ja", "", 0x00FF00)
                                                                                      ));
        
        // Create variables
        $callList_HTML = $this->RegisterVariableString("html_calllist", "Anrufliste");
        IPS_SetVariableCustomProfile($callList_HTML, "~HTMLBox");
        IPS_SetIcon($callList_HTML, "Telephone");

        $externalIP = $this->RegisterVariableString("externalip", "Externe IP-Adresse");
        IPS_SetIcon($externalIP, "Internet");
        
        $speedDownstream = $this->RegisterVariableString("speed_downstream", "Geschwindigkeit Download");
        IPS_SetIcon($speedDownstream, "HollowArrowDown");
        
        $speedUpstream = $this->RegisterVariableString("speed_upstream", "Geschwindigkeit Upload");
        IPS_SetIcon($speedUpstream, "HollowArrowUp");
        
        $statusInternetConnection = $this->RegisterVariableBoolean("status_internet_connection", "Internet Status");
        IPS_SetVariableCustomProfile($statusInternetConnection, "FBX.InternetState");
        SetValue($statusInternetConnection, false);

        $missedCallsCounter = $this->RegisterVariableInteger("missed_calls_counter", "Anzahl verpasster Anrufe");
        SetValue($missedCallsCounter, 0);

        $messageCounter = $this->RegisterVariableInteger("message_counter", "Anzahl neuer Nachrichten auf Anrufbeantworter");
        SetValue($messageCounter, 0);

        $reconnect = $this->RegisterVariableBoolean("reconnect", "Internet erneut verbinden");
        IPS_SetVariableCustomProfile($reconnect, "FBX.Reconnect");
        $this->EnableAction("reconnect");

        $restart = $this->RegisterVariableBoolean("restart", "Fritzbox Neutarten");
        IPS_SetVariableCustomProfile($restart, "FBX.Restart");
        $this->EnableAction("restart");

        $wifiGuest = $this->RegisterVariableBoolean("wifi_guest", "WLAN: Gäste");
        IPS_SetVariableCustomProfile($wifiGuest, "FBX.WLAN");
        $this->EnableAction("wifi_guest");
        
        $wifiGuest = $this->RegisterVariableBoolean("wifi_guest", "WLAN: Gäste");
        IPS_SetVariableCustomProfile($wifiGuest, "FBX.WLAN");
        $this->EnableAction("wifi_guest");
        
        $wifiGuestPassphrase = $this->RegisterVariableString("wifi_guest_passphrase", "WLAN: Gäste - WPA Schlüssel");
        IPS_SetIcon($wifiGuestPassphrase, "Key");
        IPS_SetHidden($wifiGuestPassphrase, true);
        
        $wifiMain = $this->RegisterVariableBoolean("wifi_main", "WLAN: Intern");
        IPS_SetVariableCustomProfile($wifiMain, "FBX.WLAN");
        $this->EnableAction("wifi_main");
        
        IPS_SetProperty($this->InstanceID, "FBX_REVERSE_CACHE", "[]"); // clear cache
        
        if(strlen(IPS_GetProperty($this->InstanceID, "FBX_IP")) > 0 && strlen(IPS_GetProperty($this->InstanceID, "FBX_USERNAME")) > 0 && strlen(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")) > 0) {
            $this->Update();
        }   
    }

    public function RequestAction($Ident, $Value) 
    { 
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }

        switch ($Ident) 
        { 
            case 'wifi_guest':
                $this->SetWifiState(2, (int)$Value);
                break;
            case 'wifi_main':
                $this->SetWifiState(1, (int)$Value);
                break;
            case 'reconnect':
                $this->Reconnect();
                break;
            case 'restart':
                $this->Restart();
                break;
        } 
    }

    public function GetConfigurationForm() {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) > 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) > 0) {
            $client = new SoapClient(
                null,
                array(
                    'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_voip",
                    'uri'		=> "urn:dslforum-org:service:X_VoIP:1",
                    'noroot' 	=> True,
                    'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                    'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"),
                    'exception' => 0
                )
            );
        }
        
        $elements =     '"elements":
                        [
                            { "name": "FBX_IP",                             "type": "ValidationTextBox",    "caption": "IP-Adresse" }
                            ,{ "name": "FBX_USERNAME",                       "type": "ValidationTextBox",    "caption": "Benutzername" }
                            ,{ "name": "FBX_PASSWORD",                       "type": "PasswordTextBox",      "caption": "Passwort" }
                            ,{ "label": "Anrufliste - Einstellungen",        "type": "Label" }
                            ,{ "name": "FBX_CALLLIST_COLUMNS",               "type": "ValidationTextBox",    "caption": "Spalten" }
                            ,{ "name": "FBX_CALLLIST_OUTPUT",                "type": "Select",               "caption": "Ausgabegerät",
                                "options": [
                                    { "label": "Lokales Gerät",     "value": "local" },
                                    { "label": "Sonos",             "value": "sonos" }
                                ]
                            }
                            ,{ "name": "FBX_CALLLIST_REVERSESEARCH",         "type": "Select",               "caption": "Rückwärtssuche",
                                "options": [
                                    { "label": "deaktiviert",       "value": "none" },
                                    { "label": "Das Örtliche (DE)", "value": "oertliche_de" },
                                    { "label": "Klick Tel (DE)",    "value": "klicktel_de" }
                                ]
                            }
                            ,{ "name": "FBX_CALLLIST_TIMELIMIT",             "type": "SelectObject",         "caption": "Zeit Einschränkung" }
                            ,{ "label": "Anrufliste - Anruftypen",           "type": "Label" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_1",            "type": "CheckBox",             "caption": "eingehend, angenommen" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_2",            "type": "CheckBox",             "caption": "eingehend, nicht angenommen" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_3",            "type": "CheckBox",             "caption": "ausgehend" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_4",            "type": "CheckBox",             "caption": "Anrufbeantworter, neu" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_5",            "type": "CheckBox",             "caption": "Anrufbeantworter, alt" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_6",            "type": "CheckBox",             "caption": "Anrufbeantworter, gelöscht" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_9",            "type": "CheckBox",             "caption": "eingehend, aktiv" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_10",           "type": "CheckBox",             "caption": "eingehend, abgelehnt" }
                            ,{ "name": "FBX_CALLLIST_CALLTYPE_11",           "type": "CheckBox",             "caption": "ausgehend, aktiv" }
                            ,{ "label": "Wahlkonfiguration",                 "type": "Label" }
                            ,{ "name": "FBX_DIAL_PORT",                      "type": "Select",               "caption": "Ausgangsport",
                                "options": [
                                    { "label": "", "value": "" }
                            ';
        // Build option Dial Port
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) > 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) > 0) 
        {
            for($i = 1; $i <= 21; $i++)
            {
                try {
                    $result = $client->{"X_AVM-DE_GetPhonePort"}(new SoapParam($i,'NewIndex'));
                    if(strlen(trim($result)) > 0) {
                        $elements .= ',{ "label": "'.$result.'", "value": "'.$result.'" }';
                    }
                } catch(Exception $e) { }   
            }
        }
        $elements .=    '] }';
        $elements .=    ']';

        $actions =      ',"actions":
                            [
                                { "type": "Button", "label": "Restart Fritzbox", "onClick": "FBX_Restart($id);" }
                            ]
                        ';

        $configForm = "{";
        $configForm .= $elements;
        $configForm .= $actions;
        $configForm .= "}";

        return $configForm;
    }

    // PUBLIC ACCESSIBLE FUNCTIONS
    public function Test()
    {
        $this->Update();
    }

    public function Reconnect()
    {
        SetValue($this->GetIDForIdent("reconnect"), true);
        IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Internet neu verbinden.");
        $this->FB_Reconnect();
        sleep(2);
        SetValue($this->GetIDForIdent("reconnect"), false);
    }

    public function Restart()
    {
        SetValue($this->GetIDForIdent("restart"), true);
        IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Fritzbox Neustart.");
        $this->FB_Restart();
        sleep(2);
        SetValue($this->GetIDForIdent("restart"), false);
    }
    
    public function Update() {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }

        $this->UpdateCallList();
        $this->UpdateConnectionInfos();
        $this->UpdateWifiInfos();
    }
    
    public function GetMessageAsBinary()
    {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }

        if (isset($_GET['index']))
        {
            $client = new SoapClient(
                null,
                array(
                    'location'   => "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_tam",
                    'uri'        => "urn:dslforum-org:service:X_AVM-DE_TAM:1",
                    'noroot'     => True,
                    'login'      => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                    'password'   => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
                )
            );
            if (isset($_GET['action']) && $_GET['action']=="delete") {
                $client->DeleteMessage( new SoapParam((int)$_GET['tam'], 'NewIndex'),new SoapParam((int)$_GET['index'],'NewMessageIndex') );
            }
        }

        if (isset ($_GET['path']))
        {
            $client = new SoapClient(
                null,
                array(
                    'location'   => "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/deviceconfig",
                    'uri'        => "urn:dslforum-org:service:DeviceConfig:1",
                    'noroot'     => True,
                    'login'      => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                    'password'   => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
                )
            );
            $sid = $client->{'X_AVM-DE_CreateUrlSID'}();
            $file = "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000".urldecode($_GET['path'])."&".$sid;
            @header("Content-type: audio/wave");
            @header('Content-Transfer-Encoding: binary');
            @header('Cache-Control: must-revalidate');
            readfile($file);
        }
    }
    
    public function DetailsForPhoneNumber($phoneNumber) {
        $searchResult = null;
        $cache = json_decode(IPS_GetProperty($this->InstanceID, "FBX_REVERSE_CACHE"), true);

        if(!array_key_exists($phoneNumber, $cache)) { // phonenumber not in cache
            switch (IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_REVERSESEARCH")) {
                case 'oertliche_de':
                    $result = $this->QueryDasOertlicheDe($phoneNumber);
                    if($result !== false)
                        $cache[$phoneNumber] = $result;
                    else
                        $cache[$phoneNumber] = array('Name' => "Unbekannt");      
                    break;
                case 'klicktel_de':
                    $result = $this->QueryKlickTelDe($phoneNumber);
                    if($result !== false)
                        $cache[$phoneNumber] = $result;
                    else
                        $cache[$phoneNumber] = array('Name' => "Unbekannt");  
                    break;
                default:
                    $cache[$phoneNumber] = array('Name' => "Unbekannt");
                    break;
            }
                
        }
        
        IPS_SetProperty($this->InstanceID, "FBX_REVERSE_CACHE", json_encode($cache));
        return $cache[$phoneNumber];
    }
    
    public function SetWifiState($module, $state) {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }

        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/wlanconfig".$module,
                'uri'		=> "urn:dslforum-org:service:WLANConfiguration:".$module,
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );        
        
        $client->SetEnable(new SoapParam($state, 'NewEnable'));
        $this->UpdateWifiInfos();
    }

    public function Dial($number) {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_DIAL_PORT"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Anruf an '".$number."' nicht möglich, da kein Ausgangsport gesetzt ist!");
            return false;
        }
        

        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_voip",
                'uri'		=> "urn:dslforum-org:service:X_VoIP:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $dialConfig = $client->{"X_AVM-DE_DialGetConfig"}();
        if($dialConfig == "unconfigured")
            $dialConfig = "";

        $client->{"X_AVM-DE_DialSetConfig"}(new SoapParam(IPS_GetProperty($this->InstanceID, 'FBX_DIAL_PORT'), 'NewX_AVM-DE_PhoneName'));
        sleep(0.5);
        $result = $client->{"X_AVM-DE_DialNumber"}(new SoapParam((string)$number, 'NewX_AVM-DE_PhoneNumber'));
        sleep(0.5);
        $client->{"X_AVM-DE_DialSetConfig"}(new SoapParam($dialConfig, 'NewX_AVM-DE_PhoneName'));

        return $result;
    }

    public function HangUp() {
        if(strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_USERNAME"))) == 0 || strlen(trim(IPS_GetProperty($this->InstanceID, "FBX_PASSWORD"))) == 0) 
        {
            $this->ModuleLogMessage("Fehler: Fritzbox Benutzername oder Passwort nicht gesetzt!");
            return false;
        }

        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_voip",
                'uri'		=> "urn:dslforum-org:service:X_VoIP:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $dialConfig = $client->{"X_AVM-DE_DialGetConfig"}();
        if($dialConfig == "unconfigured")
            $dialConfig = "";

        $client->{"X_AVM-DE_DialSetConfig"}(new SoapParam(IPS_GetProperty($this->InstanceID, 'FBX_DIAL_PORT'), 'NewX_AVM-DE_PhoneName'));
        sleep(0.5);
        $result = $client->{"X_AVM-DE_DialHangup"}();
        sleep(0.5);
        $client->{"X_AVM-DE_DialSetConfig"}(new SoapParam($dialConfig, 'NewX_AVM-DE_PhoneName'));

        return $result;
    }
    
    public function EnableCallDiversion($diversionNumber) {
        $fbox = new fritzbox_api($this->InstanceID);
            
        $formfields = array(
            'getpage'                             => '/fon_num/rul_list.lua',
            'rub_'.$diversionNumber               => 'on',
            'apply'                               => '',
        ); 
        $fbox->doPostForm($formfields); 
        
        $fbox = null;  
    }
    
    public function DisableCallDiversion() {
        $fbox = new fritzbox_api($this->InstanceID);
            
        $formfields = array(
            'getpage'                             => '/fon_num/rul_list.lua',
            'apply'                               => '',
        ); 
        $fbox->doPostForm($formfields);   
        
        $fbox = null;
    }
    
    public function GetAmountOfMissedCalls() {
        // return IPS_GetProperty($this->InstanceID, "FBX_AMOUNT_MISSED_CALLS");
        return GetValue($this->GetIDForIdent("missed_calls_counter"));
    }

    public function GetAmountOfMessages() {
        // return IPS_GetProperty($this->InstanceID, "FBX_AMOUNT_MESSAGES");
        return GetValue($this->GetIDForIdent("message_counter"));
    }

    // HELPER FUNCTIONS
    private function RegisterHook($Hook, $TargetID)
    {
        $ids = IPS_GetInstanceListByModuleID("{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}");
        if(sizeof($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], "Hooks"), true);
            $found = false;
            foreach($hooks as $index => $hook) {
                if($hook['Hook'] == $Hook) {
                    if($hook['TargetID'] == $TargetID)
                        return;
                    $hooks[$index]['TargetID'] = $TargetID;
                    $found = true;
                }
            }
            if(!$found) {
                $hooks[] = Array("Hook" => $Hook, "TargetID" => $TargetID);
            }
            IPS_SetProperty($ids[0], "Hooks", json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }
    
    protected function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize) {
        if(!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 1);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if($profile['ProfileType'] != 1)
            throw new Exception("Variable profile type does not match for profile ".$Name);
        }
        
        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
        
    }

    protected function RegisterProfileIntegerEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        if ( sizeof($Associations) === 0 ){
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[sizeof($Associations)-1][0];
        }
        
        $this->RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);
        
        foreach($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
        
    }

    protected function RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize) {
        if(!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 0);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if($profile['ProfileType'] != 0)
            throw new Exception("Variable profile type does not match for profile ".$Name);
        }
        
        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);  
    }
    
    protected function RegisterProfileBooleanEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        if ( sizeof($Associations) === 0 ){
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[sizeof($Associations)-1][0];
        }
        
        $this->RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);
        
        foreach($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
        
    }

    protected function GetParent()
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : false;
    }
    
    protected function UpdateCallList() {
        $callList = $this->FB_GetCallList();
        $messageList = $this->FB_GetMessageList();

        
        
        for ($i=0; $i < count($callList); $i++) {
            // Switch numbers if needed
            if ((int)$callList[$i]->Type == 3) {
                $tmp = (string)$callList[$i]->Caller;
                $callList[$i]->Caller = (string)$callList[$i]->Called;
                $callList[$i]->Called = $tmp;
            }

            // Clear own number for example ISDN: POTS: SIP: etc...
            $callList[$i]->Called = str_replace(strtoupper((string)$callList[$i]->Numbertype).": ","",(string)$callList[$i]->Called);
            $callList[$i]->addChild("AB"); // create empty message entry
            
            if (((int)$callList[$i]->Port >= 40) && ((int)$callList[$i]->Port <= 44)) {
                $callList[$i]->Type= 6; // set message deleted as preset
                
                if (strlen((string)$callList[$i]->Path) <> 0) { // get same message from calllist
                    $messageXML = $messageList[(int)$callList[$i]->Port]->xpath("//Message[Path ='".(string)$callList[$i]->Path."']");
                    $callList[$i]->Duration = "---";
                    if ($messageXML !== false) {
                        // extending callList
                        $callList[$i]->addChild("Tam");
                        $callList[$i]->Tam->addAttribute("index",(string)$messageXML[0]->Tam);
                        $callList[$i]->Tam->addChild("TamIndex",(string)$messageXML[0]->Index);
                        $callList[$i]->Type= ((string)$messageXML[0]->New == "1" ? "4" : "5");
                        $callList[$i]->AB = "1";
                        if ((string)$messageXML[0]->New == "1") {
                            $callList[$i]->Path = "?path=".urlencode((string)$callList[$i]->Path)."&tam=".(string)$messageXML[0]->Tam."&index=".(string)$messageXML[0]->Index."&action=mark";
                        } else {
                            $callList[$i]->Path = "?path=".urlencode((string)$callList[$i]->Path);
                        }
                    }
                }
            }
        }

        $this->RenderCallList($callList);
    }
    
    protected function UpdateConnectionInfos() {
        $connInfo = $this->FB_GetConnectionInfos();
        
        switch ($connInfo['NewPhysicalLinkStatus']) {
            case 'Up':
                if(GetValue($this->GetIDForIdent("status_internet_connection")) == false) {
                    SetValue($this->GetIDForIdent("status_internet_connection"), true);
                    IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Internet: Verbunden.");
                }
                break;
            default:
                if(GetValue($this->GetIDForIdent("status_internet_connection")) == true) {
                    SetValue($this->GetIDForIdent("status_internet_connection"), false);
                    IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Internet: Getrennt.");
                }
                break;
        }
        
        SetValue($this->GetIDForIdent("speed_upstream"), round($connInfo['NewLayer1UpstreamMaxBitRate']/1000000, 1)." MBit/s");
        SetValue($this->GetIDForIdent("speed_downstream"), round($connInfo['NewLayer1DownstreamMaxBitRate']/1000000, 1)." MBit/s");
        SetValue($this->GetIDForIdent("externalip"), $connInfo['ExternalIPAddress']);        
    }
    
    protected function UpdateWifiInfos() {
        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/wlanconfig1",
                'uri'		=> "urn:dslforum-org:service:WLANConfiguration:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );
        
        $status = $client->GetInfo();
        SetValue($this->GetIDForIdent("wifi_main"), (boolean)$status['NewEnable']);
                
        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/wlanconfig2",
                'uri'		=> "urn:dslforum-org:service:WLANConfiguration:2",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );
        
        $status = $client->GetInfo();
        SetValue($this->GetIDForIdent("wifi_guest"), (boolean)$status['NewEnable']);
    }
    
    protected function RenderCallList($callList=null) {
        $missedCallsCounter = 0;
        $messageCounter = 0;
        
        $HTML = "<script>
                    function playMessage(obj) {
                        var messagePath = window.location.origin+'/hook/".IPS_GetProperty($this->InstanceID, "FBX_HOOKNAME")."/'+obj.getAttribute('data-messagepath')+'&output=".IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_OUTPUT")."';  
                        console.warn(messagePath);
                        
                        if(messagePath.length > 0) {
                            var audio = new Audio(messagePath);
                            audio.play();
                        }
                    }
                 </script>";
                 
        // $HTML .= "<script src=\"https://raw.githubusercontent.com/goldfire/howler.js/2.0/howler.min.js\"></script>";

        if($callList != null) {    
            foreach ($callList as $call) {
                $proceed = false;
                if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_1") == 1 && $call->Type == 1)
                    $proceed = true;
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_2") == 1 && $call->Type == 2) {
                    $missedCallsCounter++;
                    $proceed = true;
                }
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_3") == 1 && $call->Type == 3)
                    $proceed = true;
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_4") == 1 && $call->Type == 4) {
                    $messageCounter++;
                    $proceed = true;
                }
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_5") == 1 && $call->Type == 5)
                    $proceed = true;
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_6") == 1 && $call->Type == 6)
                    $proceed = true;
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_9") == 1 && $call->Type == 9)
                    $proceed = true;
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_10") == 1 && $call->Type == 10) {
                    $missedCallsCounter++;
                    $proceed = true;
                }
                else if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_CALLTYPE_11") == 1 && $call->Type == 11)
                    $proceed = true;
                    
                if(!$proceed)
                    continue;
                
                $messageButtonVisibility = "hidden";
                if(strlen($call->Path) > 0) {
                    $messageButtonVisibility = "visible";
                }
                    
                $rowIcon = "";
                switch ($call->Type) {
                    case '1':
                        $rowIcon = "ipsIconHollowArrowRight";
                        break;
                    case '2':
                        $rowIcon = "ipsIconCross";
                        break;
                    case '3':
                        $rowIcon = "ipsIconHollowArrowLeft";
                        break;
                    case '4':
                        $rowIcon = "ipsIconMail";
                        break;
                    case '5':
                        $rowIcon = "ipsIconMail";
                        break;
                    case '6':
                        $rowIcon = "ipsIconMail";
                        break;
                    case '9':
                        $rowIcon = "ipsIconHollowArrowRight";
                        break;
                    case '10':
                        $rowIcon = "ipsIconCross";
                        break;
                    case '11':
                        $rowIcon = "ipsIconHollowArrowLeft";
                        break;
                }
                
                $columns = explode(",", str_replace(" ", "", IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_COLUMNS")));
                $columnWidth = 100/count($columns)."%";
                
                $detailDIV = "";
                $i=1;
                foreach ($columns as $column) {
                    if($i < count($columns))
                        $style = "float: left; width: ".$columnWidth."; overflow-x: hidden; margin-right: 10px;";
                    else
                        $style = "float: none; width: auto; overflow-x: hidden;";
                    
                    switch (strtolower($column)) {
                        case 'icon':
                            $detailDIV .= "<div class=\"icon td ".$rowIcon."\" style=\"".$style." height: 35px; width: 35px !important;\"></div>";
                            break;
                        case 'name':
                            $detailDIV .= "<div style=\"".$style."\">".$this->DetailsForPhoneNumber((string)$call->Caller)['Name']."</div>";
                            break;
                        default:
                            $detailDIV .= "<div style=\"".$style."\">".$call->$column."</div>";
                            break;
                    }
                    $i++;
                }
                
                $HTML .=    "<div class=\"ipsContainer container nestedEven ipsVariable\" style=\"border-color: rgba(255,255,255,0.15); border-style: solid; border-width: 0 0 1px;\">
                                <div class=\"content tr\">
                                    <div class=\"title td\" style=\"width: 100%\">
                                        <div style=\"min-width: 300px; width: 100%;\">".$detailDIV."</div>
                                    </div>
                                    <div class=\"visual td\">
                                        <div class=\"ipsContainer text colored\" style=\"background-color: rgba(255, 255, 255, 0.3); visibility: ".$messageButtonVisibility.";\" data-id=\"".$call->Id."\" data-messagepath=\"".$call->Path."\" onclick=\"playMessage(this);\">Abspielen</div>
                                    </div>
                                    <div class=\"link td\"></div>
                                </div>
                                <div class=\"extended empty\"></div>
                                <div class=\"childContainers empty\"></div>
                            </div>";
            }    
        }
        
        // IPS_SetProperty($this->InstanceID, "FBX_AMOUNT_MESSAGES", $messageCounter);
        // IPS_SetProperty($this->InstanceID, "FBX_AMOUNT_MISSED_CALLS", $missedCallsCounter);

        SetValue($this->GetIDForIdent("missed_calls_counter"), $missedCallsCounter);
        SetValue($this->GetIDForIdent("message_counter"), $messageCounter);
        
        SetValue($this->GetIDForIdent("html_calllist"), $HTML);
    }
    
    protected function QueryDasOertlicheDe($phoneNumber)
    {
        $record = false;
        $url = "http://www.dasoertliche.de/Controller?form_name=search_inv&ph=".$phoneNumber;
        # Create a DOM parser object
        $dom = new DOMDocument();
        # Parse the HTML from klicktel
        # The @ before the method call suppresses any warnings that
        # loadHTMLFile might throw because of invalid HTML or URL.
        @$dom->loadHTMLFile($url);
        if ($dom->documentURI == null)
        {
            IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Timeout bei Abruf der Webseite ".$url);
            return false;
        }
        $finder = new DomXPath($dom);
        $classname="hit clearfix ";
        $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), '$classname')]");
        if ($nodes->length == 0) return false;
        $cNode = $nodes->item(0); //div left
        if ($cNode->nodeName != 'div') return false;
        if (!$cNode->hasChildNodes()) return false;
        $ahref = $cNode->childNodes->item(1); // a href
        if (!$ahref->hasChildNodes()) return false;
        foreach ($ahref->childNodes as $div)
        {
            if ($div->nodeName == "a" ) break;
        }
        $record = array(
                        'Name' => trim($div->nodeValue)
                        );
        return $record;
    }


    protected function QueryKlickTelDe($phoneNumber)
    {
        $url = 'http://www.klicktel.de/rueckwaertssuche/'.$phoneNumber;
        # Create a DOM parser object
        $dom = new DOMDocument();
        # Parse the HTML from klicktel
        # The @ before the method call suppresses any warnings that
        # loadHTMLFile might throw because of invalid HTML or URL.
        @$dom->loadHTMLFile($url);
        if ($dom->documentURI == null)
        {
            IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Timeout bei Abruf der Webseite ".$url);
            return false;
        }
        $finder = new DomXPath($dom);
        $classname="results direct";
        $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $classname ')]");
        if ($nodes->length == 0) return false;
        $ulNode = $nodes->item(0);
        if ($ulNode->nodeName != 'ul') return false; 
        if ($ulNode->childNodes->length == 0) return false;
        $liNode = $ulNode->childNodes->item(0); 
        if ($liNode->nodeName != 'li') return false;
        if ($liNode->childNodes->length < 1) return false; 
        $divNode = $liNode->childNodes->item(1); 
        if ($divNode->nodeName != 'div') return false;
        if ($divNode->childNodes->length < 2) return false; 
        $h3Node = $divNode->childNodes->item(3); 
        if ($h3Node->tagName != 'h3') return false;
        $record = array(
            'Name' => trim(utf8_decode(utf8_decode($h3Node->nodeValue)))
        );
        return $record;
    }
    
    // Fritzbox helper functions
    protected function FB_GetCallList() {
        $client = new SoapClient(
            null,
            array(
                    'location'   => "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_contact",
                    'uri'        => "urn:dslforum-org:service:X_AVM-DE_OnTel:1",
                    'noroot'     => True,
                    'login'      => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                    'password'   => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );
        $xml = @simplexml_load_file($client->GetCallList());
        if ($xml === false)
        {
            IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Fehler beim laden der callList!");
            return false;
        }
        $xml = new simpleXMLElement($xml->asXML());
        
        $timelimit = 0;
        if(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_TIMELIMIT") > 0)
            $timelimit = GetValue(IPS_GetProperty($this->InstanceID, "FBX_CALLLIST_TIMELIMIT"));
        
        $callList_filtered = array();
        
        foreach ($xml->Call as $call) {
            if($timelimit > 0) { // filter calls by timestamp
                $parts = preg_split('/[ .:]/', (string)$call->Date);
                
                $callTimestamp = mktime((int)$parts[3], (int)$parts[4], 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
                
                if($callTimestamp < $timelimit)
			        continue;
            }
            
            $callList_filtered[] = $call;
        }
        
        return $callList_filtered;
    }
    
    protected function FB_GetMessageList()
    {
        $client = new SoapClient(
            null,
            array(
                'location'   => "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/x_tam",
                'uri'        => "urn:dslforum-org:service:X_AVM-DE_TAM:1",
                'noroot'     => True,
                'login'      => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'   => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );
        
        for ($i=0;$i<5;$i++)
        {
            $GetInfo = $client->GetInfo(new SoapParam($i,"NewIndex"));
            if ($GetInfo["NewName"] <> "")
            {
                $URL = $client->GetMessageList(new SoapParam($i,"NewIndex"));
                $xml = @simplexml_load_file($URL);
                if ($xml === false)
                {
                    IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], "Fehler beim laden der Nachrichten!");
                    return false;
                }
                $xml = new simpleXMLElement($xml->asXML());
                $MessageList[40+$i]=$xml;
            }
        }
        
        return $MessageList;
    }
    
    protected function FB_GetConnectionInfos()
    {
        $return = array();

        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/igdupnp/control/WANCommonIFC1",
                'uri'		=> "urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $return = array_merge($return, $client->GetCommonLinkProperties());

        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/igdupnp/control/WANIPConn1",
                'uri'		=> "urn:schemas-upnp-org:service:WANIPConnection:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $return["ExternalIPAddress"] = $client->GetExternalIPAddress();

        // $return = array_merge($return, );
        
        return $return;
    }

    protected function FB_Reconnect()
    {
        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/igdupnp/control/WANIPConn1",
                'uri'		=> "urn:schemas-upnp-org:service:WANIPConnection:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $client->ForceTermination();
    }

    protected function FB_Restart()
    {
        $client = new SoapClient(
            null,
            array(
                'location'	=> "http://".IPS_GetProperty($this->InstanceID, "FBX_IP").":49000/upnp/control/deviceconfig",
                'uri'		=> "urn:dslforum-org:service:DeviceConfig:1",
                'noroot' 	=> True,
                'login'     => IPS_GetProperty($this->InstanceID, "FBX_USERNAME"),
                'password'  => IPS_GetProperty($this->InstanceID, "FBX_PASSWORD")
            )
        );

        $client->Reboot();
    }

    protected function ModuleLogMessage($message) {
        IPS_LogMessage(IPS_GetObject($this->InstanceID)['ObjectName'], $message);
    }
}

?>
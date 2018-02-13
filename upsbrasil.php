<?php
class UpsBrasil extends CarrierModule
{
	const PREFIX = 'ups_brasil';
	const SETTINGS = [
		'ups' => [
			'nr_conta' => '',//9999XX
			'nr_chaveAcesso' => '',//9999XX9999
			'tnt_key' => '',//XX99XX9999X99XXX
			'tnt_user' => '',//XXXXXX
			'tnt_pass' => '',//XXXXXXXXXX
		],
		'address' => [
			'uf' => '',//XX
			'cep' => '',//99999999
			'city' => '',//e.g. Brasília
			'country' => 'BR'//Always BR
		],
	];

	//For control change of the carrier's ID (id_carrier), the module must use the updateCarrier hook.	
	protected $_hooks = ['actionCarrierUpdate'];

	//"Public carrier name" => "technical name",	
	protected $_carriers = ['Ups - United Parcel Service of America' => 'upscarrier'];
	
	public function __construct()
	{
		$this->name = 'upsbrasil';
		$this->tab = 'shipping_logistics';
		$this->version = '1.6.1';
		$this->author = 'Fernando Souza';
		$this->bootstrap = true;
	
		parent::__construct();
	
		$this->displayName = $this->l('UPS Brasil');
		$this->description = $this->l('Exibe custo de envio via UPS.');
	}

	public function uninstall()
	{
		if (parent::uninstall()) {
			foreach ($this->_hooks as $hook) {
				if (!$this->unregisterHook($hook)) {
					return false;
				}
			}
	
			if (!$this->deleteCarriers()) {
				return false;
			}
	
			return true;
		}
	
		return false;
	}

	public function install()
	{
		if (parent::install()) {
			foreach ($this->_hooks as $hook) {
				if (!$this->registerHook($hook)) {
					return false;
				}
			}
	
			if (!$this->createCarriers()) { //function for creating new currier
				return false;
			}
	
			return true;
		}
	
		return false;
	}
	
	protected function createCarriers()
	{
		foreach ($this->_carriers as $key => $value) {
			//Create new carrier
			$carrier = new Carrier();
			$carrier->name = $key;
			$carrier->active = true;
			$carrier->deleted = 0;
			$carrier->shipping_handling = false;
			$carrier->range_behavior = 0;
			$carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = $key;
			$carrier->shipping_external = true;
			$carrier->is_module = true;
			$carrier->external_module_name = $this->name;
			$carrier->need_range = true;
	
			if ($carrier->add()) {
				$groups = Group::getGroups(true);
				foreach ($groups as $group) {
					Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_group', [
						'id_carrier' => (int) $carrier->id,
						'id_group' => (int) $group['id_group']
					], 'INSERT');
				}
	
				$rangePrice = new RangePrice();
				$rangePrice->id_carrier = $carrier->id;
				$rangePrice->delimiter1 = '0';
				$rangePrice->delimiter2 = '1000000';
				$rangePrice->add();
	
				$rangeWeight = new RangeWeight();
				$rangeWeight->id_carrier = $carrier->id;
				$rangeWeight->delimiter1 = '0';
				$rangeWeight->delimiter2 = '1000000';
				$rangeWeight->add();
	
				$zones = Zone::getZones(true);
				foreach ($zones as $z) {
					Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_zone',
						array('id_carrier' => (int) $carrier->id, 'id_zone' => (int) $z['id_zone']), 'INSERT');
					Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery',
						array('id_carrier' => $carrier->id, 'id_range_price' => (int) $rangePrice->id, 'id_range_weight' => NULL, 'id_zone' => (int) $z['id_zone'], 'price' => '0'), 'INSERT');
					Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery',
						array('id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int) $rangeWeight->id, 'id_zone' => (int) $z['id_zone'], 'price' => '0'), 'INSERT');
				}
	
				copy(dirname(__FILE__) . '/views/img/' . $value . '.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg'); //assign carrier logo
	
				Configuration::updateValue(self::PREFIX . $value, $carrier->id);
				Configuration::updateValue(self::PREFIX . $value . '_reference', $carrier->id);
			}
		}
	
		return true;
	}

	protected function deleteCarriers()
	{
		foreach ($this->_carriers as $value) {
			$tmp_carrier_id = Configuration::get(self::PREFIX . $value);
			$carrier = new Carrier($tmp_carrier_id);
			$carrier->delete();
		}
	
		return true;
	}

	public function getOrderShippingCost($params, $shipping_cost)
	{
		if ($entrega = $this->getCusto($params)) {
			$this->context->smarty->assign('prazo_ups', $this->getPrazo($params));

			return (float) $entrega;
		}

		return false;
	}
	
	public function getOrderShippingCostExternal($params)
	{	
		return $this->getOrderShippingCost($params, 0);
	}

	public function hookActionCarrierUpdate($params)
	{
		if ($params['carrier']->id_reference == Configuration::get(self::PREFIX . 'swipbox_reference')) {
			Configuration::updateValue(self::PREFIX . 'swipbox', $params['carrier']->id);
		}
	}

	private function getCusto($params)
	{
		//Configuration	
		try {

			$wsdl = _PS_MODULE_DIR_."/upsbrasil/UPS_Billing.WSDL";
			$operation = "UPS_Retorno_Frete";
			$endpointurl = ('http://189.57.214.75:8081/UPS_Billing.asmx?wsdl');

			$mode = [
				'soap_version' => 'SOAP_1_1',  // use soap 1.1 client
				'trace' => 1,
				'exceptions' => true,
				'cache_wsdl' => WSDL_CACHE_NONE,
			];
		
			// initialize soap client
			$client = new SoapClient($wsdl , $mode);
		
			//set endpoint url
			$client->__setLocation($endpointurl);
			  
			//create soap header		
			if ($requestData = $this->processaFrete($params)) {
				if ($this->checkCepRange($requestData['ParametroFrete']['nr_cep_destino'])) {					
					$resp = $client->__soapCall( "UPS_Retorno_Frete" ,array('UPS_Retorno_Frete' => $requestData )) ;

					if ($resp instanceof stdClass) { 
						$retorno = simplexml_load_string($resp->UPS_Retorno_FreteResult->any);
						
						$valorFrete = (float)$retorno->NewDataSet->Table->FreteTotalReceber;

						return $valorFrete;
					} else {
						return false;
					}			
				} else {
					return false;
				}		
			}
		
		} catch (\SoapFault $fault) {
			return false;
		}

		return true;
	}

	private function processaFrete($params)
	{
		// Only for logged customers
		if ($this->context->customer->isLogged()) {		
			$addressCustomer = new Address($params->id_address_delivery);
			$estadoCustomer = new State($addressCustomer->id_state);
			// Get customer CEP
			if ($addressCustomer->postcode) {
				$cepDestino = $addressCustomer->postcode;
			}

			if ($estadoCustomer->name) {
				$estadoDestino = $estadoCustomer->name;
			}
		} else {
			return false;
		}

		$cepDestino = trim(preg_replace("/[^0-9]/", "", $cepDestino));

		// Ignore Carrier in case of invalid CEP size		
		if (strlen($cepDestino) != 8) {
			return false;
		}

		$contProdutos = 0;
		$pesoPedido = 0;
		$precoPedido = 0;
		$alturaPedido = 0;
		$larguraPedido = 0;
		$comprimentoPedido = 0;

		foreach ($params->getProducts() as $prod) {

			// Ignore virtual products
			if ($prod['is_virtual'] == 1) {
				continue;
			}

			for ($qty = 0; $qty < $prod['quantity']; $qty++) {

				$contProdutos++;

				$pesoPedido += $prod['weight'];
				$precoPedido += $prod['unit_price'];
				$altura = number_format($prod['height'], 0, '', '');
				$largura = number_format($prod['width'], 0, '', '');
				$comprimento =  number_format($prod['depth'], 0, '', '');
				
				$alturaPedido += $altura;
				$larguraPedido += $largura;
				$comprimentoPedido += $comprimento;

				$produtos = [
					'id' => $prod['id_product'],
					'altura' => substr($altura, 0, 6),
					'largura' => substr($largura, 0, 6),
					'comprimento' => substr($comprimento, 0, 6),
					'peso' => substr(str_replace('.', '', (string)$prod['weight']), 0, 6),
				];
			}
		}    

		$params = [];
		$aut = [];		
		
		//set parameters for soap request
		$aut['nr_conta'] = self::SETTINGS['ups']['nr_conta'];		
		$aut['nr_chaveAcesso'] = self::SETTINGS['ups']['nr_chaveAcesso'];

		$params['autenticacao'] = $aut;
		$params['nr_peso'] = $pesoPedido;
		$params['nr_conta'] = self::SETTINGS['ups']['nr_conta'];
		$params['nr_cep_origem'] = self::SETTINGS['address']['cep'];
		$params['nr_cep_destino'] = $cepDestino;
		$params['nr_quantidade_pacotes'] = '1';
		$params['vl_valor_mercadoria'] = $precoPedido;
		$params['nm_cidade_origem'] = self::SETTINGS['address']['city'];
		$params['nm_cidade_destino'] = $addressCustomer->city;		
		$params['ds_dimensional'] = $larguraPedido.'/'.$alturaPedido.'/'.$comprimentoPedido;

        $request['ParametroFrete'] = $params;

		return $request;		
	}

	function getPrazo($params)
	{
		$wsdl = _PS_MODULE_DIR_."/upsbrasil/TNTWS.wsdl";
		$operation = "ProcessTimeInTransit";
		$endpointurl = 'https://onlinetools.ups.com/webservices/TimeInTransit';
		
		try {
			$mode = [
 				'soap_version' => 'SOAP_1_1',  // use soap 1.1 client
				'trace' => 1,
				'exceptions' => true,				
				'cache_wsdl' => WSDL_CACHE_NONE,
			];
	  
			// initialize soap client
			$client = new SoapClient($wsdl, $mode);
	  
			//set endpoint url
			$client->__setLocation($endpointurl);
	  
			//create soap header
			$usernameToken['Username'] = self::SETTINGSSETTINGS['ups']['tnt_user'];
			$usernameToken['Password'] = self::SETTINGS['ups']['tnt_pass'];
			$upsService['UsernameToken'] = $usernameToken;

			$serviceAccessLicense['AccessLicenseNumber'] = self::SETTINGS['ups']['tnt_key'];
			$upsService['ServiceAccessToken'] = $serviceAccessLicense;
		
			$header = new SoapHeader('http://www.ups.com/XMLSchema/XOLTWS/UPSS/v1.0', 'UPSSecurity', $upsService);
			$client->__setSoapHeaders($header);
	  
		    if ($requestData = $this->processaPrazoData($params)) {
				//get response
				$resp = $client->__soapCall($operation , array($requestData));

				if ($resp instanceof stdClass) {
					return (int) $resp->TransitResponse->ServiceSummary->EstimatedArrival->TotalTransitDays;
				} else {
					return false;
				}
		    }
		} catch (\SoapFault $fault) {
			return false;
		}

		return true;		
	}
	
	private function processaPrazoData($params)
	{
		// Only for logged customers
		if ($this->context->customer->isLogged()) {		
			$addressCustomer = new Address($params->id_address_delivery);
			$estadoCustomer = new State($addressCustomer->id_state);

			// Get customer CEP
			$cepDestino = $addressCustomer->postcode;
			$estadoDestino = $estadoCustomer->name;	
		} else {
			return false;
		}

		$cepDestino = trim(preg_replace("/[^0-9]/", "", $cepDestino));
		
		// Ignore Carrier in case of invalid CEP size
		if (strlen($cepDestino) != 8) {
			return false;
		}

		// Products
		foreach ($params->getProducts() as $prod) {
				
			// Ignore virtual products
			if ($prod['is_virtual'] == 1) {
				continue;
			}

			for ($qty = 0; $qty < $prod['quantity']; $qty++) {
				$pesoPedido += $prod['weight'];			
			}
		}        				
		
		//create soap request
		$option['RequestOption'] = 'TNT';
		$shipment['Request'] = $option;
  
		//From address
		$address['City'] = self::SETTINGS['address']['city'];
		$address['StateProvinceCode'] = self::SETTINGS['address']['uf'];
		$address['PostalCode'] = self::SETTINGS['address']['cep'];
		$address['CountryCode'] = self::SETTINGS['address']['country'];
		$shipper['Address'] = $address;
		
		$shipment['ShipFrom'] = $shipper;
  
		//To address
		$addressTo['City'] = $addressCustomer->city;
		$addressTo['StateProvinceCode'] = $estadoDestino;
		$addressTo['PostalCode'] = $cepDestino;
		$addressTo['CountryCode'] = 'BR';
		$shipto['Address'] = $addressTo;
		
		$shipment['ShipTo'] = $shipto;
		
		$shipment['Pickup']['Date'] = date('Ymd');

		$shipment['ShipmentWeight']['UnitOfMeasurement']['Code'] = 'KGS';
		$shipment['ShipmentWeight']['UnitOfMeasurement']['Description'] = 'Kilogramm';
		$shipment['ShipmentWeight']['Weight'] = $pesoPedido;
		
		$shipment['TotalPackagesInShipment'] = '1';

		return $shipment;
	}
	
	private function checkCepRange($cepAtual)
	{
		$ceps = $this->getCeps();	
		$cepAtual = str_replace('-','',$cepAtual);
		$valido = false;

		foreach ($ceps as $cep) {
			if ($cepAtual >= $cep[0] && $cepAtual <= $cep[1]) {
				$valido = true;
			}
		}
		
		return $valido;
	}	
	
	/*
	*ALLOWED CEPS @TODO CREATE MODULE CONFIGURATION SCREEN TO SET THE ALLOWED CEPS
	*/
	private function getCeps()
	{
		return [
			['01000000','03599999'],
			['03600000','03799999'],
			['03800000','05999999'],
			['06000000','06299999'],
			['06300000','06399999'],
			['06400000','06499999'],
			['06500000','06549999'],
			['06600000','06649999'],
			['06750000','06729999'],
			['07000000','07399999'],
			['07400000','07499999'],
			['07750000','07799999'],
			['08000000','08499999'],
			['08500000','08549999'],
			['08550000','08569999'],
			['08570000','08599999'],
			['08600000','08699999'],
			['08700000','08899999'],
			['09000000','09299999'],
			['09300000','09399999'],
			['09400000','09449999'],
			['09450000','09499999'],
			['09500000','09599999'],
			['09600000','09799999'],
			['09800000','09899999'],
			['09900000','09999999'],
			['11000000','11199999'],
			['11300000','11399999'],
			['11400000','11499999'],
			['11500000','11599999'],
			['11660000','11679999'],
			['11680000','11680999'],
			['11700000','11729999'],
			['12000000','12119999'],
			['12140000','12140999'],
			['12200001','12248999'],
			['12260000','12260999'],
			['12280000','12299999'],
			['12300000','12349999'],
			['12400000','12449999'],
			['12500000','12524999'],
			['12570000','12570999'],
			['12600000','12614999'],
			['13000000','13139999'],
			['13140000','13149999'],
			['13170000','13182999'],
			['13183000','13189999'],
			['13200000','13219999'],
			['13220000','13229999'],
			['13230000','13239999'],
			['13270000','13279999'],
			['13280000','13280999'],
			['13290000','13290999'],
			['13295000','13295999'],
			['13300000','13314999'],
			['13320000','13329999'],
			['13330000','13349999'],
			['13400000','13427999'],
			['13450000','13459999'],
			['13460000','13460999'],
			['13465000','13479999'],
			['13480000','13489999'],
			['13490000','13490999'],
			['13495000','13495999'],
			['13500000','13507999'],
			['13510000','13510999'],
			['13560000','13577999'],
			['13600000','13609999'],
			['13610000','13624999'],
			['13630000','13644999'],
			['13660000','13660999'],
			['13690000','13690999'],
			['13800000','13816999'],
			['13820000','13820999'],
			['13825000','13825999'],
			['13840000','13854999'],
			['13857000','13857999'],
			['13920000','13920999'],
			['14000000','14109999'],
			['14140000','14140999'],
			['14150000','14150999'],
			['14160000','14179999'],
			['14300000','14300999'],
			['14400000','14414999'],
			['14415000','14415999'],
			['14440000','14440999'],
			['14460000','14460999'],
			['14470000','14470999'],
			['14500000','14500999'],
			['14570000','14570999'],
			['14580000','14580999'],
			['14620000','14620999'],
			['14700000','14718999'],
			['14730000','14730999'],
			['14735000','14735999'],
			['14750000','14750999'],
			['14800000','14811999'],
			['14815000','14815999'],
			['14860000','14860999'],
			['14870000','14898999'],
			['15000000','15104999'],
			['15110000','15110999'],
			['15130000','15130999'],
			['15170000','15170999'],
			['15350000','15350999'],
			['15355000','15355999'],
			['15370000','15370999'],
			['15400000','15400999'],
			['15500000','15525999'],
			['15530000','15530999'],
			['15600000','15600999'],
			['15700000','15700999'],
			['15800000','15819999'],
			['15828000','15828999'],
			['15895000','15895999'],
			['15990000','15990999'],
			['16000000','16129999'],
			['16130000','16130999'],
			['16200000','16209999'],
			['16260000','16260999'],
			['16300000','16300999'],
			['16370000','16370999'],
			['16400000','16419999'],
			['16430000','16430999'],
			['16700000','16700999'],
			['16800000','16800999'],
			['16880000','16880999'],
			['16900000','16914999'],
			['17000000','17109999'],
			['17120000','17120999'],
			['17200000','17220999'],
			['17280000','17280999'],
			['17400000','17400999'],
			['17430000','17430999'],
			['17440000','17440999'],
			['17470000','17470999'],
			['17475000','17475999'],
			['17500000','17539999'],
			['17540000','17540999'],
			['17560000','17560999'],
			['17580000','17580999'],
			['17700000','17700999'],
			['17730000','17730999'],
			['17760000','17760999'],
			['17780000','17780999'],
			['17800000','17800999'],
			['17830000','17830999'],
			['17860000','17860999'],
			['17880000','17880999'],
			['18000000','18099999'],
			['18100000','18119999'],
			['18270000','18282999'],
			['18520000','18520999'],
			['18540000','18540999'],
			['18550000','18550999'],
			['18600000','18619999'],
			['18650000','18650999'],
			['18680000','18687999'],
			['18700000','18709999'],
			['19000000','19140999'],
			['19160000','19160999'],
			['19180000','19180999'],
			['19300000','19300999'],
			['19360000','19360999'],
			['19400000','19400999'],
			['19470000','19470999'],
			['19500000','19500999'],
			['19570000','19570999'],
			['19600000','19600999'],
			['19700000','19700999'],
			['19780000','19780999'],
			['19800000','19819999'],
			['19820000','19820999'],
			['19830000','19830999'],
			['19900000','19919999'],
			['19930000','19930999'],
			['20010000','20010180'],
			['20011000','20011040'],
			['20020000','20020100'],
			['20021000','20021390'],
			['20030000','20030140'],
			['20031000','20031205'],
			['20040000','20040070'],
			['20041000','20041020'],
			['20050000','20050040'],
			['20050060','20050060'],
			['20050090','20050100'],
			['20050190','20050190'],
			['20051001','20051080'],
			['20060000','20060080'],
			['20061000','20061050'],
			['20070000','20070030'],
			['20071000','20071004'],
			['20080001','20080032'],
			['20080050','20080070'],
			['20080101','20080102'],
			['20081000','20081010'],
			['20081050','20081050'],
			['20081240','20081250'],
			['20090000','20090080'],
			['20091000','20091060'],
			['20210010','20210010'],
			['20211005','20211010'],
			['20211030','20211040'],
			['20211130','20211130'],
			['20211330','20211360'],
			['20221030','20221030'],
			['20221240','20221291'],
			['20221400','20221430'],
			['20230010','20230070'],
			['20230110','20230261'],
			['20231003','20231050'],
			['20231082','20231110'],
			['20240000','20240000'],
			['20240030','20240030'],
			['20240050','20240051'],
			['20240180','20240180'],
			['20240200','20240200'],
			['20241060','20241060'],
			['20241080','20241080'],
			['20241110','20241110'],
			['20241150','20241190'],
			['20241220','20241220'],
			['22010000','22010070'],
			['22010100','22010122'],
			['22011001','22011060'],
			['22011080','22011100'],
			['22020001','22020070'],
			['22021000','22021050'],
			['22030000','22030050'],
			['22031000','22031070'],
			['22031090','22031130'],
			['22040001','22040050'],
			['22041001','22041090'],
			['22050001','22050030'],
			['22051000','22051030'],
			['22060001','22060040'],
			['22061000','22061080'],
			['22070000','22070020'],
			['22071000','22071120'],
			['22080000','22080070'],
			['22081000','22081060'],
			['22210000','22210080'],
			['22211090','22211190'],
			['22211230','22211230'],
			['22220030','22220060'],
			['22220080','22220080'],
			['22221000','22221000'],
			['22221070','22221150'],
			['22230000','22230090'],
			['22231000','22231230'],
			['22240000','22240180'],
			['22241000','22241020'],
			['22245000','22245150'],
			['22250000','22250210'],
			['22251000','22251090'],
			['22260000','22260240'],
			['22261000','22261170'],
			['22270000','22270080'],
			['22271000','22271110'],
			['22280002','22280130'],
			['22281000','22281150'],
			['22290000','22290090'],
			['22290110','22290190'],
			['22290210','22290210'],
			['22290230','22290240'],
			['22290260','22290290'],
			['22291000','22291220'],
			['22410000','22410100'],
			['22411000','22411080'],
			['22420000','22420043'],
			['22421000','22421030'],
			['22430000','22430220'],
			['22431000','22431080'],
			['22440000','22440060'],
			['22441000','22441140'],
			['22450000','22450221'],
			['22460000','22460140'],
			['22460170','22460320'],
			['22461000','22461260'],
			['22470001','22470051'],
			['22470110','22470240'],
			['22470280','22470300'],
			['22471002','22471003'],
			['22471060','22471270'],
			['22471310','22471340'],
			['22610001','22610390'],
			['22611000','22611300'],
			['22620000','22620400'],
			['22621000','22621310'],
			['22630000','22630440'],
			['22631000','22631470'],
			['22640000','22640320'],
			['22641002','22641220'],
			['22641250','22641340'],
			['22641370','22641370'],
			['22641420','22641720'],
			['22710010','22710010'],
			['22753000','22753190'],
			['22753215','22753215'],
			['22753660','22753660'],
			['22753705','22753705'],
			['22753730','22753740'],
			['22753845','22753845'],
			['22775002','22775003'],
			['22775040','22775055'],
			['22780160','22780160'],
			['22780790','22780790'],
			['22783010','22783010'],
			['22783410','22783410'],
			['22783560','22783560'],
			['22785210','22785220'],
			['22785250','22785260'],
			['22785340','22785480'],
			['22785560','22785560'],
			['22790000','22790850'],
			['22793000','22793840'],
			['22795000','22795520'],
			['22795560','22795590'],
			['22795641','22795650'],
			['22795690','22795870'],
		];
	}
}
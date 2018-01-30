<?php
class UpsBrasil extends CarrierModule
{
	const PREFIX = 'ups_brasil';

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

			$wsdl = "UPS_Billing.WSDL";
			$operation = "UPS_Retorno_Frete";
			$endpointurl = ('http://189.57.214.75:8081/UPS_Billing.asmx?wsdl');

			$mode = array
			(
				 'soap_version' => 'SOAP_1_1',  // use soap 1.1 client
				 'trace' => 1,
				 'exceptions' => true,
				 'cache_wsdl' => WSDL_CACHE_NONE,
			);
		
			// initialize soap client
			$client = new SoapClient($wsdl , $mode);
		
			//set endpoint url
			$client->__setLocation($endpointurl);
			  
			//create soap header		
			if($requestData = $this->processaFrete($params))
			{
				if($this->checkCepRange($requestData['ParametroFrete']['nr_cep_destino']))
				{					
					$resp = $client->__soapCall( "UPS_Retorno_Frete" ,array('UPS_Retorno_Frete' => $requestData )) ;

					if($resp instanceof stdClass)
					{ 
						$retorno = simplexml_load_string($resp->UPS_Retorno_FreteResult->any);
						
						$valorFrete = (float)$retorno->NewDataSet->Table->FreteTotalReceber;

						return $valorFrete;
					}else
					{
						return false;
					}			
				}else
				{
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
		$aut['nr_conta'] = 'YOURUPSACCOUNT';		
		$aut['nr_chaveAcesso'] = 'ACCESKEY';

		$params['autenticacao'] = $aut;
		$params['nr_peso'] = $pesoPedido;
		$params['nr_conta'] = 'YOURUPSACCOUNT';
		$params['nr_cep_origem'] = 'YOURCEP';
		$params['nr_cep_destino'] = $cepDestino;
		$params['nr_quantidade_pacotes'] = '1';
		$params['vl_valor_mercadoria'] = $precoPedido;
		$params['nm_cidade_origem'] = 'CITY';
		$params['nm_cidade_destino'] = $addressCustomer->city;		
		$params['ds_dimensional'] = $larguraPedido.'/'.$alturaPedido.'/'.$comprimentoPedido;

        $request['ParametroFrete'] = $params;

		return $request;		
	}

	function getPrazo($params)
	{
		$access = "TNTACCESSKEY";
		$userid = "USER";
		$passwd = "PASSWORD";
		$wsdl = "TNTWS.wsdl";
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
			$usernameToken['Username'] = $userid;
			$usernameToken['Password'] = $passwd;
			$upss['UsernameToken'] = $usernameToken;

			$serviceAccessLicense['AccessLicenseNumber'] = $access;
			$upss['ServiceAccessToken'] = $serviceAccessLicense;
		
			$header = new SoapHeader('http://www.ups.com/XMLSchema/XOLTWS/UPSS/v1.0', 'UPSSecurity', $upss);
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
		$address['City'] = 'CITY';
		$address['StateProvinceCode'] = 'UF';
		$address['PostalCode'] = 'CEP';
		$address['CountryCode'] = 'BR';
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
	*ALLOWED CEPS @TODO CREATE MODULE CONFIGURATION SCREEN TO SET ALLOWED CEPS
	*/
	private function getCeps()
	{
		return array(
			array('01000000','03599999'),
			array('03600000','03799999'),
			array('03800000','05999999'),
			array('06000000','06299999'),
			array('06300000','06399999'),
			array('06400000','06499999'),
			array('06500000','06549999'),
			array('06600000','06649999'),
			array('06750000','06729999'),
			array('07000000','07399999'),
			array('07400000','07499999'),
			array('07750000','07799999'),
			array('08000000','08499999'),
			array('08500000','08549999'),
			array('08550000','08569999'),
			array('08570000','08599999'),
			array('08600000','08699999'),
			array('08700000','08899999'),
			array('09000000','09299999'),
			array('09300000','09399999'),
			array('09400000','09449999'),
			array('09450000','09499999'),
			array('09500000','09599999'),
			array('09600000','09799999'),
			array('09800000','09899999'),
			array('09900000','09999999'),
			array('11000000','11199999'),
			array('11300000','11399999'),
			array('11400000','11499999'),
			array('11500000','11599999'),
			array('11660000','11679999'),
			array('11680000','11680999'),
			array('11700000','11729999'),
			array('12000000','12119999'),
			array('12140000','12140999'),
			array('12200001','12248999'),
			array('12260000','12260999'),
			array('12280000','12299999'),
			array('12300000','12349999'),
			array('12400000','12449999'),
			array('12500000','12524999'),
			array('12570000','12570999'),
			array('12600000','12614999'),
			array('13000000','13139999'),
			array('13140000','13149999'),
			array('13170000','13182999'),
			array('13183000','13189999'),
			array('13200000','13219999'),
			array('13220000','13229999'),
			array('13230000','13239999'),
			array('13270000','13279999'),
			array('13280000','13280999'),
			array('13290000','13290999'),
			array('13295000','13295999'),
			array('13300000','13314999'),
			array('13320000','13329999'),
			array('13330000','13349999'),
			array('13400000','13427999'),
			array('13450000','13459999'),
			array('13460000','13460999'),
			array('13465000','13479999'),
			array('13480000','13489999'),
			array('13490000','13490999'),
			array('13495000','13495999'),
			array('13500000','13507999'),
			array('13510000','13510999'),
			array('13560000','13577999'),
			array('13600000','13609999'),
			array('13610000','13624999'),
			array('13630000','13644999'),
			array('13660000','13660999'),
			array('13690000','13690999'),
			array('13800000','13816999'),
			array('13820000','13820999'),
			array('13825000','13825999'),
			array('13840000','13854999'),
			array('13857000','13857999'),
			array('13920000','13920999'),
			array('14000000','14109999'),
			array('14140000','14140999'),
			array('14150000','14150999'),
			array('14160000','14179999'),
			array('14300000','14300999'),
			array('14400000','14414999'),
			array('14415000','14415999'),
			array('14440000','14440999'),
			array('14460000','14460999'),
			array('14470000','14470999'),
			array('14500000','14500999'),
			array('14570000','14570999'),
			array('14580000','14580999'),
			array('14620000','14620999'),
			array('14700000','14718999'),
			array('14730000','14730999'),
			array('14735000','14735999'),
			array('14750000','14750999'),
			array('14800000','14811999'),
			array('14815000','14815999'),
			array('14860000','14860999'),
			array('14870000','14898999'),
			array('15000000','15104999'),
			array('15110000','15110999'),
			array('15130000','15130999'),
			array('15170000','15170999'),
			array('15350000','15350999'),
			array('15355000','15355999'),
			array('15370000','15370999'),
			array('15400000','15400999'),
			array('15500000','15525999'),
			array('15530000','15530999'),
			array('15600000','15600999'),
			array('15700000','15700999'),
			array('15800000','15819999'),
			array('15828000','15828999'),
			array('15895000','15895999'),
			array('15990000','15990999'),
			array('16000000','16129999'),
			array('16130000','16130999'),
			array('16200000','16209999'),
			array('16260000','16260999'),
			array('16300000','16300999'),
			array('16370000','16370999'),
			array('16400000','16419999'),
			array('16430000','16430999'),
			array('16700000','16700999'),
			array('16800000','16800999'),
			array('16880000','16880999'),
			array('16900000','16914999'),
			array('17000000','17109999'),
			array('17120000','17120999'),
			array('17200000','17220999'),
			array('17280000','17280999'),
			array('17400000','17400999'),
			array('17430000','17430999'),
			array('17440000','17440999'),
			array('17470000','17470999'),
			array('17475000','17475999'),
			array('17500000','17539999'),
			array('17540000','17540999'),
			array('17560000','17560999'),
			array('17580000','17580999'),
			array('17700000','17700999'),
			array('17730000','17730999'),
			array('17760000','17760999'),
			array('17780000','17780999'),
			array('17800000','17800999'),
			array('17830000','17830999'),
			array('17860000','17860999'),
			array('17880000','17880999'),
			array('18000000','18099999'),
			array('18100000','18119999'),
			array('18270000','18282999'),
			array('18520000','18520999'),
			array('18540000','18540999'),
			array('18550000','18550999'),
			array('18600000','18619999'),
			array('18650000','18650999'),
			array('18680000','18687999'),
			array('18700000','18709999'),
			array('19000000','19140999'),
			array('19160000','19160999'),
			array('19180000','19180999'),
			array('19300000','19300999'),
			array('19360000','19360999'),
			array('19400000','19400999'),
			array('19470000','19470999'),
			array('19500000','19500999'),
			array('19570000','19570999'),
			array('19600000','19600999'),
			array('19700000','19700999'),
			array('19780000','19780999'),
			array('19800000','19819999'),
			array('19820000','19820999'),
			array('19830000','19830999'),
			array('19900000','19919999'),
			array('19930000','19930999'),
			array('20010000','20010180'),
			array('20011000','20011040'),
			array('20020000','20020100'),
			array('20021000','20021390'),
			array('20030000','20030140'),
			array('20031000','20031205'),
			array('20040000','20040070'),
			array('20041000','20041020'),
			array('20050000','20050040'),
			array('20050060','20050060'),
			array('20050090','20050100'),
			array('20050190','20050190'),
			array('20051001','20051080'),
			array('20060000','20060080'),
			array('20061000','20061050'),
			array('20070000','20070030'),
			array('20071000','20071004'),
			array('20080001','20080032'),
			array('20080050','20080070'),
			array('20080101','20080102'),
			array('20081000','20081010'),
			array('20081050','20081050'),
			array('20081240','20081250'),
			array('20090000','20090080'),
			array('20091000','20091060'),
			array('20210010','20210010'),
			array('20211005','20211010'),
			array('20211030','20211040'),
			array('20211130','20211130'),
			array('20211330','20211360'),
			array('20221030','20221030'),
			array('20221240','20221291'),
			array('20221400','20221430'),
			array('20230010','20230070'),
			array('20230110','20230261'),
			array('20231003','20231050'),
			array('20231082','20231110'),
			array('20240000','20240000'),
			array('20240030','20240030'),
			array('20240050','20240051'),
			array('20240180','20240180'),
			array('20240200','20240200'),
			array('20241060','20241060'),
			array('20241080','20241080'),
			array('20241110','20241110'),
			array('20241150','20241190'),
			array('20241220','20241220'),
			array('22010000','22010070'),
			array('22010100','22010122'),
			array('22011001','22011060'),
			array('22011080','22011100'),
			array('22020001','22020070'),
			array('22021000','22021050'),
			array('22030000','22030050'),
			array('22031000','22031070'),
			array('22031090','22031130'),
			array('22040001','22040050'),
			array('22041001','22041090'),
			array('22050001','22050030'),
			array('22051000','22051030'),
			array('22060001','22060040'),
			array('22061000','22061080'),
			array('22070000','22070020'),
			array('22071000','22071120'),
			array('22080000','22080070'),
			array('22081000','22081060'),
			array('22210000','22210080'),
			array('22211090','22211190'),
			array('22211230','22211230'),
			array('22220030','22220060'),
			array('22220080','22220080'),
			array('22221000','22221000'),
			array('22221070','22221150'),
			array('22230000','22230090'),
			array('22231000','22231230'),
			array('22240000','22240180'),
			array('22241000','22241020'),
			array('22245000','22245150'),
			array('22250000','22250210'),
			array('22251000','22251090'),
			array('22260000','22260240'),
			array('22261000','22261170'),
			array('22270000','22270080'),
			array('22271000','22271110'),
			array('22280002','22280130'),
			array('22281000','22281150'),
			array('22290000','22290090'),
			array('22290110','22290190'),
			array('22290210','22290210'),
			array('22290230','22290240'),
			array('22290260','22290290'),
			array('22291000','22291220'),
			array('22410000','22410100'),
			array('22411000','22411080'),
			array('22420000','22420043'),
			array('22421000','22421030'),
			array('22430000','22430220'),
			array('22431000','22431080'),
			array('22440000','22440060'),
			array('22441000','22441140'),
			array('22450000','22450221'),
			array('22460000','22460140'),
			array('22460170','22460320'),
			array('22461000','22461260'),
			array('22470001','22470051'),
			array('22470110','22470240'),
			array('22470280','22470300'),
			array('22471002','22471003'),
			array('22471060','22471270'),
			array('22471310','22471340'),
			array('22610001','22610390'),
			array('22611000','22611300'),
			array('22620000','22620400'),
			array('22621000','22621310'),
			array('22630000','22630440'),
			array('22631000','22631470'),
			array('22640000','22640320'),
			array('22641002','22641220'),
			array('22641250','22641340'),
			array('22641370','22641370'),
			array('22641420','22641720'),
			array('22710010','22710010'),
			array('22753000','22753190'),
			array('22753215','22753215'),
			array('22753660','22753660'),
			array('22753705','22753705'),
			array('22753730','22753740'),
			array('22753845','22753845'),
			array('22775002','22775003'),
			array('22775040','22775055'),
			array('22780160','22780160'),
			array('22780790','22780790'),
			array('22783010','22783010'),
			array('22783410','22783410'),
			array('22783560','22783560'),
			array('22785210','22785220'),
			array('22785250','22785260'),
			array('22785340','22785480'),
			array('22785560','22785560'),
			array('22790000','22790850'),
			array('22793000','22793840'),
			array('22795000','22795520'),
			array('22795560','22795590'),
			array('22795641','22795650'),
			array('22795690','22795870'),
		);
	}
}
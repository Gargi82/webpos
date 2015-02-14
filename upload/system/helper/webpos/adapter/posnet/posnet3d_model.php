<?php
class posnet3DModel {
	public function methodResponse($bank){
		$response=array();
		$getResult=$this->createForm($bank);
		if(is_array($getResult)){
			$response['error']=$getResult['error'];
		} else {
			$response['form']=$getResult;
		}
		//$response['redirect']=;
		//$response['error']=;
		//$response['form'];
		return $response;
		
	}
	
		private function createForm($bank) {
		$posnetRequest=$this->oosRequest($bank);
		$xml = simplexml_load_string($posnetRequest);
		$approved=isset($xml->approved)?$xml->approved:'';
		if($approved!=1) {
			$form=array('error'=>'Posnet ön onay Hatası: '.$xml->resptext);
		} else if ($approved==1) {
			$data1 = $xml->oosRequestDataResponse->data1;
			$data2 = $xml->oosRequestDataResponse->data2;
			$sign = $xml->oosRequestDataResponse->sign;
			$inputs=array();
			$inputs=array('posnetData'=>$data1,
			'posnetData2'=>$data2,
			'mid'=>$bank['posnet_merchant_id'],
			'posnetID'=>['posnet_posnet_id'],
			'digest'=>$sign,
			'vftCode'=>"K001",
			'merchantReturnURL'=>$bank['success_url'],
			'lang'=>"tr",
			'url'=>"",
			'openANewWindow'=>"0",
			'useJokerVadaa'=>"1");
			$action='';
			if ($bank['mode']=='live') {
				$action=$bank['posnet_3D_url'];
			} else if ($bank['mode']=='test') {
				$action=$bank['posnet_test_url'];
			}
			
			$form='<form id="webpos_form" name="webpos_form" method="post" action="'.$action.'">';
			foreach($inputs as $key=>$value){ 

				$form.='<input type="hidden" name="'.$key.'" value="'.$value.'" />';

			} 
			$form.='</form>';
			
		}
		
		

		return $form;
		
	}
	
	private function oosRequest($bank) {
		$xid=substr("00000000000000000000".$bank['oid'],-20);
		$expDate=$bank['cc_expire_date_year'].$bank['cc_expire_date_month'];
		if($bank['instalment']!=0){
			$instalment=$bank['instalment'];
		} else {
			$instalment="00";
		}
		
		$xml="xmldata=<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>".
		"<posnetRequest>".
		"<mid>".$bank['posnet_merchant_id']."</mid>".
		"<tid>".$bank['posnet_terminal_id']."</tid>".
		"<oosRequestData>".
		"<posnetid>".$bank['posnet_posnet_id']."</posnetid>".
		"<ccno>".$bank['cc_number']."</ccno>".
		"<expDate>".$expDate."</expDate>".
		"<cvc>".$bank['cc_cvv2']."</cvc>".
		"<amount>".$bank['total']."</amount>".
		"<currencyCode>YT</currencyCode>".
		"<installment>".$instalment."</installment>".
		"<XID>".$xid."</XID>".
		"<cardHolderName>".$bank['cc_name']."</cardHolderName>".
		"<tranType>Sale</tranType>".
		"</oosRequestData>".
		"</posnetRequest>";
		
		$url=$bank['posnet_classic_url'];
		
		$result=$this->curlSend($url,$xml);
		return $result;
	}

	public function bankResponse($bank_response,$bank){
		$response=array();
		$response['message']='';
		$merchantData =isset($bank_response['MerchantPacket'])?$bank_response['MerchantPacket']:"";    
		$bankData = isset($bank_response['BankPacket'])?$bank_response['BankPacket']:"";   
		$sign = isset($bank_response['Sign'])?$bank_response['Sign']:"";
		$url=$bank['posnet_classic_url'];
		$oosResponse = $this->oosResolve($bank['posnet_merchant_id'],$bank['posnet_terminal_id'],$bankData,$merchantData,$sign,$url);
		$xml = simplexml_load_string($oosResponse);
		$approved = $xml->approved;
		$mdStatus =$xml->oosResolveMerchantDataResponse->mdStatus;
		if (($approved == 1) && ($mdStatus==1)){ 
			$oosTran=$this->oosTran($bank['posnet_merchant_id'],$bank['posnet_terminal_id'],$bankData,$url);
			$xmlTran=simplexml_load_string($oosTran);
			$approvedTran = $xmlTran->approved;
			if ($approvedTran==1){
				$hostlogkey = $xmlTran->hostlogkey;
				$authCode = $xmlTran->authCode;
				$inst1 = $xmlTran->instInfo->inst1;
				$amnt1 = $xmlTran->instInfo->amnt1;
				$response['result']=1;
				$response['message'].='Ödeme Başarılı<br/>';
				$response['message'].='AuthCode : '.$authCode.'<br/>';
				$response['message'].='HostLogKey : '.$hostlogkey.'<br/>';
				$response['message'].='Instalment : '.$inst1.'<br/>';
				$response['message'].='Amount : '.$amnt1.'<br/>';
			} else {
				$response['result']=0;
				$response['message'].=$xmlTran->respText;
			}
		} else {
			$response['result']=0;
			$response['message'].=$xmlTran->respText;
		}
		return $response;
	}
		private function oosResolve($mid,$tid,$bankData,$merchantData,$sign,$url){
		$xml="xmldata=<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>".
		"<posnetRequest>".
		"<mid>".$mid."</mid>".
		"<tid>".$tid."</tid>".
		"<oosResolveMerchantData>".
		"<bankData>".$bankData."</bankData>".
		"<merchantData>".$merchantData."</merchantData>".
		"<sign>".$sign."</sign>".
		"</oosResolveMerchantData>".
		"</posnetRequest>";
		$result=$this->curlSend($url,$xml);
		return $result;
	}
	private function oosTran($mid,$tid,$bankData,$url){
		$xml="xmldata=<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>".
		"<posnetRequest>".
		"<mid>".$mid."</mid>".
		"<tid>".$tid."</tid>".
		"<oosTranData>".
		"<bankData>".$bankData."</bankData>".
		"<wpAmount>0</wpAmount>".
		"</oosTranData>".
		"</posnetRequest>";
		$result=$this->curlSend($url,$xml);
		return $result;
	}
	private function curlSend($url,$xml){
		$posnet_static_ip ='';//have to fill if there is some conflict with server ip
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if($posnet_static_ip!='') {curl_setopt($ch, CURLOPT_INTERFACE, $posnet_static_ip);}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 90);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, urlencode($xml));
		
		
		$result = curl_exec($ch);
		if (curl_errno($ch)) {
			$result='<posnetResponse>
			<approved>0</approved>
			<respcode>cUrlError</respcode>
			<resptext>cUrl Error: '.curl_error($ch).'</resptext>
			</posnetResponse>';
		}
		
		curl_close($ch);
		return $result;
	}

}
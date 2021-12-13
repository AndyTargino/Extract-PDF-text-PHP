<?php


class decodTexto
{


	var $multibyte = 4; //unicode
	var $converterDados = ENT_QUOTES;

	var $estadoAtual = true; //TRUE se houver algum problema com o limite de tempo
	var $nomeArquivo = '';
	var $novoTXT = '';

	function setnomeArquivo($nomeArquivo)
	{
		$this->novoTXT = '';
		$this->nomeArquivo = $nomeArquivo;
	}

	function saida($echo = false)
	{
		if ($echo) echo $this->novoTXT;
		else return $this->novoTXT;
	}

	function Unicode($entrada)
	{
		if ($entrada == true) $this->multibyte = 4;
		else $this->multibyte = 2;
	}

	function DecodificarDados()
	{
		$infile = @file_get_contents($this->nomeArquivo, FILE_BINARY);
		if (empty($infile))
			return "";

		$modificacao = array();
		$textos = array();

		preg_match_all("#obj[\n|\r](.*)endobj[\n|\r]#ismU", $infile . "endobj\r", $objetos);
		$objetos = @$objetos[1];

		// Seleciona Objetos dentro da Stream
		for ($i = 0; $i < count($objetos); $i++) {
			$dadoAtual = $objetos[$i];

			// time-out
			@set_time_limit(10);
			if ($this->estadoAtual) {
				flush();
				ob_flush();
			}

			// Verificar se o objeto tem Dados entre stream e endstream
			if (preg_match("#stream[\n|\r](.*)endstream[\n|\r]#ismU", $dadoAtual . "endstream\r", $stream)) {
				$stream = ltrim($stream[1]);

				// Verificar os parâmetros do objeto e procurar dados de texto.
				// Os dados sempre estão dentro de parenteses
				// 1 0 0 1 381.575 569.701 <- dados de posicionamento | conteudo em texto -> Tm (dados) Tj
				$opcoes = $this->opcoesDoObjeto($dadoAtual);

				if (!(empty($opcoes["Length1"]) && empty($opcoes["Type"]) && empty($opcoes["Subtype"])))
					continue;

				// Se não houber texto, acontecerá isso.
				unset($opcoes["Length"]);

				// Se houver texto, este código será executado
				$data = $this->streamDecodificada($stream, $opcoes);

				if (strlen($data)) {
					if (preg_match_all("#BT[\n|\r](.*)ET[\n|\r]#ismU", $data . "ET\r", $conteudoDoTexto)) {
						$conteudoDoTexto = @$conteudoDoTexto[1];
						$this->dirtytextos($textos, $conteudoDoTexto);
					} else
						$this->caractereModificado($modificacao, $data);
				}
			}
		}

		/*   Analise os texto após a modificação e retorne os resultados.   */

		$this->novoTXT = $this->textoJaModificado($textos, $modificacao);
	}

	/* 	textoJaModificado
	A Parte de decodifição procura o padrão do código e recupera
	os valores a partir da decodificação, essa parte é importante
	para a leitura dos dados.
	Para ler sobre, entre em: 
	https://blog.honeynet.org.my/2010/06/29/pdf-stream-filter-part-1/ 
	*/


	function decodAscii($entrada)
	/* Exemplo: https://stackoverflow.com/questions/7488538/convert-hex-to-ascii-characters */
	{
		$saida = "";

		$impar = true;
		$comentario = false;

		for ($i = 0, $largeCode = -1; $i < strlen($entrada) && $entrada[$i] != '>'; $i++) {
			$c = $entrada[$i];

			if ($comentario) {
				if ($c == '\r' || $c == '\n')
					$comentario = false;
				continue;
			}

			switch ($c) {
				case '\0':
				case '\t':
				case '\r':
				case '\f':
				case '\n':
				case ' ':
					break;
				case '%':
					$comentario = true;
					break;

				default:
					$code = hexdec($c);
					if ($code === 0 && $c != '0')
						return "";

					if ($impar)
						$largeCode = $code;
					else
						$saida .= chr($largeCode * 16 + $code);

					$impar = !$impar;
					break;
			}
		}

		if ($entrada[$i] != '>')
			return "";

		if ($impar)
			$saida .= chr($largeCode * 16);

		return $saida;
	}

	function decodAscii85($entrada)
	/* Exemplo: https://hotexamples.com/examples/-/FilterASCII85/decode/php-filterascii85-decode-method-examples.html */
	{
		$saida = "";

		$comentario = false;
		$ords = array();

		for ($i = 0, $estadoAtual = 0; $i < strlen($entrada) && $entrada[$i] != '~'; $i++) {
			$c = $entrada[$i];

			if ($comentario) {
				if ($c == '\r' || $c == '\n')
					$comentario = false;
				continue;
			}

			if ($c == '\0' || $c == '\t' || $c == '\r' || $c == '\f' || $c == '\n' || $c == ' ')
				continue;
			if ($c == '%') {
				$comentario = true;
				continue;
			}
			if ($c == 'z' && $estadoAtual === 0) {
				$saida .= str_repeat(chr(0), 4);
				continue;
			}
			if ($c < '!' || $c > 'u')
				return "";

			$code = ord($entrada[$i]) & 0xff;
			$ords[$estadoAtual++] = $code - ord('!');

			if ($estadoAtual == 5) {
				$estadoAtual = 0;
				for ($sum = 0, $j = 0; $j < 5; $j++)
					$sum = $sum * 85 + $ords[$j];
				for ($j = 3; $j >= 0; $j--)
					$saida .= chr($sum >> ($j * 8));
			}
		}
		if ($estadoAtual === 1)
			return "";
		elseif ($estadoAtual > 1) {
			for ($i = 0, $sum = 0; $i < $estadoAtual; $i++)
				$sum += ($ords[$i] + ($i == $estadoAtual - 1)) * pow(85, 4 - $i);
			for ($i = 0; $i < $estadoAtual - 1; $i++) {
				try {
					if (false == ($o = chr($sum >> ((3 - $i) * 8)))) {
						throw new Exception('Error');
					}
					$saida .= $o;
				} catch (Exception $e) { /*Dont do anything*/
				}
			}
		}

		return $saida;
	}

	function decodFlate($data)
	/* Exemplo: https://pdf-insecurity.org/pdf-dangerous-paths/attacks.html 
				https://blog.didierstevens.com/2008/05/19/pdf-stream-objects/*/
	{
		return @gzuncompress($data);
	}

	function opcoesDoObjeto($objeto)

	{
		$opcoes = array();

		if (preg_match("#<<(.*)>>#ismU", $objeto, $opcoes)) {
			$opcoes = explode("/", $opcoes[1]);
			@array_shift($opcoes);

			$o = array();
			for ($j = 0; $j < @count($opcoes); $j++) {
				$opcoes[$j] = preg_replace("#\s+#", " ", trim($opcoes[$j]));
				if (strpos($opcoes[$j], " ") !== false) {
					$parts = explode(" ", $opcoes[$j]);
					$o[$parts[0]] = $parts[1];
				} else
					$o[$opcoes[$j]] = true;
			}
			$opcoes = $o;
			unset($o);
		}

		return $opcoes;
	}

	function streamDecodificada($stream, $opcoes)
	{
		$data = "";
		if (empty($opcoes["Filter"]))
			$data = $stream;
		else {
			$length = !empty($opcoes["Length"]) ? $opcoes["Length"] : strlen($stream);
			$_stream = substr($stream, 0, $length);

			foreach ($opcoes as $key => $value) {
				if ($key == "decodAscii")
					$_stream = $this->decodAscii($_stream);
				elseif ($key == "decodAscii85")
					$_stream = $this->decodAscii85($_stream);
				elseif ($key == "decodFlate")
					$_stream = $this->decodFlate($_stream);
				elseif ($key == "Crypt") { // TO DO
				}
			}
			$data = $_stream;
		}
		return $data;
	}


	//https://www.php.net/manual/pt_BR/function.preg-match-all.php



	function dirtyTextos(&$textos, $conteudoDoTexto)
	{
		for ($j = 0; $j < count($conteudoDoTexto); $j++) {
			if (preg_match_all("#\[(.*)\]\s*TJ[\n|\r]#ismU", $conteudoDoTexto[$j], $parts))
				$textos = array_merge($textos, array(@implode('', $parts[1])));
			elseif (preg_match_all("#T[d|w|m|f]\s*(\(.*\))\s*Tj[\n|\r]#ismU", $conteudoDoTexto[$j], $parts))
				$textos = array_merge($textos, array(@implode('', $parts[1])));
			elseif (preg_match_all("#T[d|w|m|f]\s*(\[.*\])\s*Tj[\n|\r]#ismU", $conteudoDoTexto[$j], $parts))
				$textos = array_merge($textos, array(@implode('', $parts[1])));
		}
	}

	function caractereModificado(&$modificacao, $stream)
	{
		preg_match_all("#([0-9]+)\s+beginbfchar(.*)endbfchar#ismU", $stream, $caracteres, PREG_SET_ORDER);
		preg_match_all("#([0-9]+)\s+beginbfrange(.*)endbfrange#ismU", $stream, $campos, PREG_SET_ORDER);

		for ($j = 0; $j < count($caracteres); $j++) {
			$count = $caracteres[$j][1];
			$atual = explode("\n", trim($caracteres[$j][2]));
			for ($k = 0; $k < $count && $k < count($atual); $k++) {
				if (preg_match("#<([0-9a-f]{2,4})>\s+<([0-9a-f]{4,512})>#is", trim($atual[$k]), $map))
					$modificacao[str_pad($map[1], 4, "0")] = $map[2];
			}
		}
		for ($j = 0; $j < count($campos); $j++) {
			$count = $campos[$j][1];
			$atual = explode("\n", trim($campos[$j][2]));
			for ($k = 0; $k < $count && $k < count($atual); $k++) {
				if (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+<([0-9a-f]{4})>#is", trim($atual[$k]), $map)) {
					$from = hexdec($map[1]);
					$to = hexdec($map[2]);
					$_from = hexdec($map[3]);

					for ($m = $from, $n = 0; $m <= $to; $m++, $n++)
						$modificacao[sprintf("%04X", $m)] = sprintf("%04X", $_from + $n);
				} elseif (preg_match("#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+\[(.*)\]#ismU", trim($atual[$k]), $map)) {
					$from = hexdec($map[1]);
					$to = hexdec($map[2]);
					$parts = preg_split("#\s+#", trim($map[3]));

					for ($m = $from, $n = 0; $m <= $to && $n < count($parts); $m++, $n++)
						$modificacao[sprintf("%04X", $m)] = sprintf("%04X", hexdec($parts[$n]));
				}
			}
		}
	}


	/* Aqui o código finaliza, a ultima função verifica valores das strings e retorna eles na tela */
	function textoJaModificado($textos, $modificacao)
	{
		$document = "";
		for ($i = 0; $i < count($textos); $i++) {
			$isHex = false;
			$isPlain = false;

			$hex = "";
			$plain = "";
			for ($j = 0; $j < strlen($textos[$i]); $j++) {
				$c = $textos[$i][$j];
				switch ($c) {
					case "<":
						$hex = "";
						$isHex = true;
						$isPlain = false;
						break;
					case ">":
						$hexs = str_split($hex, $this->multibyte); 
						/* 
						2 ou 4 (UTF8 ou ISO) 

						UTF-8 é uma codificação multibyte que pode representar qualquer 
						caractere Unicode. A ISO 8859-1 é uma codificação de byte único 
						que pode representar os primeiros 256 caracteres Unicode. 
							Ambos codificam ASCII exatamente da mesma maneira.
						*/ 
						for ($k = 0; $k < count($hexs); $k++) {

							$chex = str_pad($hexs[$k], 4, "0"); // Filtra para zero https://www.php.net/manual/pt_BR/function.str-pad.php
							if (isset($modificacao[$chex]))
								$chex = $modificacao[$chex];
							$document .= html_entity_decode("&#x" . $chex . ";");
						}
						$isHex = false;
						break;
					case "(":
						$plain = "";
						$isPlain = true;
						$isHex = false;
						break;
					case ")":
						$document .= $plain;
						$isPlain = false;
						break;
					case "\\":
						$c2 = $textos[$i][$j + 1];
						if (in_array($c2, array("\\", "(", ")"))) $plain .= $c2;
						elseif ($c2 == "n") $plain .= '\n';
						elseif ($c2 == "r") $plain .= '\r';
						elseif ($c2 == "t") $plain .= '\t';
						elseif ($c2 == "b") $plain .= '\b';
						elseif ($c2 == "f") $plain .= '\f';
						elseif ($c2 >= '0' && $c2 <= '9') {
							$oct = preg_replace("#[^0-9]#", "", substr($textos[$i], $j + 1, 3));
							$j += strlen($oct) - 1;
							$plain .= html_entity_decode("&#" . octdec($oct) . ";", $this->quotes);
						}
						$j++;
						break;

					default:
						if ($isHex)
							$hex .= $c;
						elseif ($isPlain)
							$plain .= $c;
						break;
				}
			}
			// Esta linha pode ser modificada
			$document .= "|";
		}

		$new = explode("#", $document);
		
		$tabDataArray = [];

		foreach($new as $value){			
			if(preg_match("/Qty/", $value)){	
				$newTable = str_replace("Fees:", " @ ", $value);
				$explodeNewTable = explode("@", $newTable);
				$explodeNewTable = str_replace("Price", "@" ,$explodeNewTable[0]);
				$explodeNewTable = explode("@", $explodeNewTable);
				$explodeNewTable = explode("|", $explodeNewTable[1]);
				$arrayAux = [];
				foreach($explodeNewTable as $value3){
					if(preg_match("/,/",  $value3)){
						$valueAux = str_replace("Program Technical Support","" ,$value3);
						array_push($arrayAux,  $valueAux);
						array_push($tabDataArray, $arrayAux);
						$arrayAux = [];
						break;
					}
					array_push($arrayAux, $value3);
				}
			}
		}
		return $tabDataArray;
	}
}

//Anderson Targino da Silva 







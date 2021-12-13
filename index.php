<?php

$arquivo = 'file1.pdf';
$subext = substr($arquivo, 0, -4);
$newext = $subext.'.txt';
include('extrair.php');
$decod = new decodTexto();
$decod->setnomeArquivo($arquivo,'r'); 
$decod->DecodificarDados();
$resultado = $decod->saida();

foreach($resultado as $value) {
    foreach($value as $tableValue){
        echo $tableValue;
        echo "<br>";
    }
    echo "<hr>";
}

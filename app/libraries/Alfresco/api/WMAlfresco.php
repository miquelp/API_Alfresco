<?php 
/*
 *		@Fecha: 21 Agosto 2014
 *		@Ult. Actualizacion: 21 Agosto 2014
 * 		@Autor: Daniel Ojeda Sandoval
 *      @Email: danielojeda@workmate.cl
 * 		@Version: 1.0
 */

/*
* 		@ejemplo de uso:
*			$conexion = WMAlfresco::getInstance();
*			$conexion->conectar($urlRepositorio,$Usuario,$Pass);
*			$conexion->checkRespuesta();
*			$conexion->setCarpetaPorRuta($carpeta);
*/

require_once ("../../phpclient/trunk/atom/cmis/cmis_repository_wrapper.php");
require_once ("../../phpclient/trunk/atom/cmis/cmis_service.php");

class WMAlfresco{

	private static $instancia;
	public $urlRepositorio;
	public $usuario;
	public $pass;
	public $repositorio;
	public $carpetaPadre;

	// Singleton

	function __construct()
	{
		# de momento nada
	}

	public static function getInstance() {
		if (!isset(self::$instancia)) {
			$obj = __CLASS__;
			self::$instancia = new $obj;
		}
		return self::$instancia;
	}


	public function __clone(){
        trigger_error('Clone no se permite.', E_USER_ERROR);
    }

	/*
		Función para conectar con alfresco.
		@ejemplo
			$url = "http://127.0.0.1:8080/alfresco/cmisatom";
	*/

	public function conectar($url,$usuario,$pass){
		$this->urlRepositorio = $url;
		$this->usuario = $usuario;
		$this->pass = $pass;
		$this->repositorio = new CMISService($url,$usuario,$pass);
	}

	public function checkRespuesta(){
		if ($this->repositorio->getLastRequest()->code > 299){
	        print "Problema con el requerimiento";
	        exit (255);
    	}
	}

	/*
	Setea carpeta sobre la cual trabajaremos en alfresco
	@Ej:
		$carpeta = "/carpeta";
	*/

	public function setCarpetaPorRuta($carpeta,$opciones = array()){
		$obj = $this->repositorio->getObjectByPath($carpeta,$opciones);
		$propiedad = $obj->properties['cmis:baseTypeId'];
		/*
		echo "<pre>obj: ";
		print_r($obj);
		echo "</pre>";
		*/
		if($propiedad != "cmis:folder"){
			print "El objeto no es una carpeta";
			exit (255);
		}
		else{
			$this->carpetaPadre = $obj;			
		}
	}

	/*
	Setea carpeta (según id) sobre la cual trabajaremos en alfresco
	@Ej:
		$id = "workspace://SpacesStore/xxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxx"	
	*/

	public function setCarpetaPorId($id,$opciones = array()){
		$obj = $this->repositorio->getObject($id,$opciones);
		$propiedad = $obj->properties["cmis:baseTypeId"];
		if ($propiedad != "cmis:folder") {
			print "El objeto no es una carpeta";
			exit (255);
		}
		else{
			$this->carpetaPadre = $obj;
		}
	}

	/*
	Crea carpeta
	crea carpeta en la carpeta que ha sido SETEADA
	$nombre = nombre que tendrá la carpeta a crear.
	@Ej:
		$nombre = "carpeta";
	*/

	public function crearCarpeta($nombre,$propiedades = array(),$opciones = array()){
		$existe = $this->existeCarpeta($nombre);
		if ($existe){
			print "Error:->La carpeta ".$nombre." ya existe";
			exit (255);
		}
		else{
			return $this->repositorio->createFolder($this->carpetaPadre->id,$nombre);	
		}
	}

	/*
	Crear archivo
	crea archivo en la carpeta que ha sido SETEADA
	($this->carpetaPadre)
	$nombre = nombre del archivo a crear.
	@Ej:
		$nombre = "archivo.txt";
		$contenido = "hola este es un archivo";
	*/

	public function crearArchivo($nombre,$propiedades = array(),$contenido = null,$tipo_contenido = "application/octet-stream",$opciones = array()){
		$existe = $this->existeArchivo($nombre);
		if ($existe) {
			print "Error:->El archivo ".$nombre." ya existe";
			exit (255);
		}
		else{
			return $this->repositorio->createDocument($this->carpetaPadre->id,$nombre,$propiedades,$contenido,$tipo_contenido,$opciones);
		}
	}

	/*
	sube archivos	
	@Ej:
		$archivo = "c:/temp/hola.pdf";
	*/
	
	public function subirArchivo($archivo){
		//deben estar en base 64
		$nombre = basename($archivo);
		$archivoAbierto = fopen($archivo, "r");
		$contenido = fread($archivoAbierto, filesize($archivo));
		//activar extension php fileinfo
		$tipo_contenido = mime_content_type($archivo);
		$nuevoArchivo = $this->crearArchivo($nombre,array(),$contenido,$tipo_contenido,array());
		if ($nuevoArchivo) {
			return $nuevoArchivo;
		}
			
	}

	/*
	Descarga de archivos según su id
	*/

	public function descargarArchivo($id){
		$archivo = $this->getObjetoPorId($id);
		$nombre = $archivo->properties["cmis:name"];
		$mime = $archivo->properties["cmis:contentStreamMimeType"];
		$tamaño = $archivo->properties["cmis:contentStreamLength"];
		$contenido = $this->repositorio->getContentStream($id);
		$nombre = str_replace(" ", "_", $nombre);
		$archTemporal = fopen($nombre, "wb");
		fwrite($archTemporal, $contenido);
		fclose($archTemporal);
		$dominio = $_SERVER['SERVER_NAME'];
		$path = getcwd()."/".$nombre;
		header ("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header('Content-Description: File Transfer');
        header('Content-type: '.$mime);
        header("Content-Disposition: attachment; filename=\"" . $nombre . "\"\n");
        header('Content-Transfer-Encoding: Binary');
        header('Expires: 0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($nombre));
        ob_clean();
        flush();
        readfile($path);
        unlink($nombre);
        exit();
	}

	/*
	descarga carpeta comprimida sólo si no tiene subcarpetas.
	*/

	public function descargarCarpeta($id){
		$tieneCarpetas = $this->tieneCarpetas($id);
		//si no tiene carpetas
		if (!$tieneCarpetas) {
			$obj = $this->getObjetoPorId($id);
			$nombreCarpeta = $obj->properties["cmis:name"];
			$ruta = getcwd()."/".$nombreCarpeta;
			$creaCarpeta = mkdir($ruta);
			if ($creaCarpeta) {
				$hijos = $this->getHijosId($id);
				$archivos = array();
				for ($i=0; $i < count($hijos->objectList); $i++) { 
						$nombreArchivo = $hijos->objectList[$i]->properties["cmis:name"];
						$tamaño = $hijos->objectList[$i]->properties["cmis:contentStreamLength"];
						$contenido = $this->repositorio->getContentStream($hijos->objectList[$i]->id);
						$archTemporal = fopen($ruta."/".$nombreArchivo, "wb");
						fwrite($archTemporal, $contenido);
						fclose($archTemporal);
						$archivos[$i] = $ruta."/".$nombreArchivo;
					}
				$zip = new ZipArchive();
				$nombreZip = $nombreCarpeta.".zip";
				if ($zip->open($ruta."/".$nombreZip, ZipArchive::CREATE)!==TRUE) {
				    exit("no se puede abrir <$nombreZip>\n");
				}
				for ($i=0; $i < count($archivos) ; $i++) { 
					$zip->addFile($archivos[$i],basename($archivos[$i]));
				}				
				$zip->close();
				$rutaZip = $ruta."/".$nombreZip;
				header ("Cache-Control: must-revalidate, post-check=0, pre-check=0");
				header('Content-Description: File Transfer');
		        header('Content-type: application/zip');
		        header("Content-Disposition: attachment; filename=\"" . $nombreZip . "\"\n");
		        header('Content-Transfer-Encoding: Binary');
		        header('Expires: 0');
		        header('Pragma: public');
		        header('Content-Length: ' . filesize($rutaZip));
		        ob_clean();
		        flush();
		        readfile($rutaZip);
		        $this->eliminarDir($ruta);
		        exit();
			}
		}
		else{
			print("no se puede descargar la carpeta");
			exit(255);
		}
	}

	/*
	Obtiene los hijos de la carpeta SETEADA
	*/

	public function getHijosCarpeta(){
		return $this->repositorio->getChildren($this->carpetaPadre->id);
	}

	/*
	Obtiene los hijos de una carpeta según Id
	*/

	public function getHijosId($id){
		return $this->repositorio->getChildren($id);
	}

	/*
	Obtiene objeto por su id
	*/

	public function getObjetoPorId($id){
		return $this->repositorio->getObject($id);
	}

	/*
	Borra objeto según su Id
	*/

	public function borrar($id,$opciones = array()){
		return $this->repositorio->deleteObject($id,$opciones);
	}

	/*
	*
	*	USO INTERNO
	*
		Verifica si la carpeta existe en la carpeta padre antes de crearla
	*/

	private function existeCarpeta($nombre){
		$obj = $this->repositorio->getChildren($this->carpetaPadre->id);
		$nombre = str_replace("(", "", $nombre);
		$nombre = str_replace(")", "", $nombre);
		$sigue = true;
		$c=0;
		while ($sigue and $c < count($obj->objectList)) {
			if ($obj->objectList[$c]->properties["cmis:objectTypeId"] == "cmis:folder") {
				if ($obj->objectList[$c]->properties["cmis:name"] == $nombre) {
					$sigue = false;
				}
			}
			$c=$c+1;
		}
		if(!$sigue){
			return true;	
		} 
		else{
			return false;
		}
	}

	private function existeArchivo($nombre){
		$obj = $this->repositorio->getChildren($this->carpetaPadre->id);
		$nombre = str_replace("(", "", $nombre);
		$nombre = str_replace(")", "", $nombre);
		$sigue = true;
		$c=0;
		while ($sigue and $c < count($obj->objectList)) {
			if ($obj->objectList[$c]->properties["cmis:objectTypeId"] == "cmis:document") {
				if ($obj->objectList[$c]->properties["cmis:name"] == $nombre) {
					$sigue = false;
				}
			}
			$c=$c+1;
		}
		if(!$sigue){
			return true;	
		} 
		else{
			return false;
		}
	}

	private function tieneCarpetas($id){
		$obj = $this->repositorio->getChildren($id);
		$sigue = true;
		$c=0;
		while ($sigue and $c < count($obj->objectList)) {
			if ($obj->objectList[$c]->properties["cmis:objectTypeId"] == "cmis:folder") {
				$sigue = false;
			}
			$c = $c+1;
		}
		if (!$sigue) {
			return true;
		}
		else{
			return false;
		}
	}

	private function eliminarDir($dir){
	    $current_dir = opendir($dir);
	    while($entryname = readdir($current_dir)){
	        if(is_dir("$dir/$entryname") and ($entryname != "." and $entryname!="..")){
	            deldir("${dir}/${entryname}");  
	        }
	        elseif($entryname != "." and $entryname!=".."){
	            unlink("${dir}/${entryname}");
	        }
	    }
	    closedir($current_dir);
	    rmdir(${'dir'});
	}
}
?>
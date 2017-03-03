<?php
namespace trebla\simpleMailer;

use Swift_Mailer;
use Swift_Message;
use RuntimeException;
use Twig_Environment;
use Twig_Loader_Filesystem;

class Mailer
{

    protected $swiftmailer;

    protected $username;
    protected $password;

    protected $to;
    protected $subject;
    protected $messageType;
    protected $messageBody;
    protected $attachments;
    protected $inlineImages;
    protected $messageObject;

    protected $twigLoader;
    protected $twigObject;

    const TRANSPORT_MAIL = 0;
    const TRANSPORT_SMTP = 1;
    const TRANSPORT_SENDMAIL = 2;

    /**
     *
     *
     */
    function __construct($transport=self::TRANSPORT_MAIL, $username='contactoweb@boomdish.com', $password='')
    {
        $this->username = $username;
        $this->password = $password;
        $this->attachments = array();
        $this->inlineImages = array();

        $this->transport     = self::newTransportInstance($transport, $username, $password);
        $this->swiftmailer   = Swift_Mailer::newInstance($this->transport);
        $this->messageObject = Swift_Message::newInstance();
    }

    /**
     * Asignar los destinatarios al email a ser enviado
     *
     * Puede recibir como parametro un arreglo de destinatarios o varios
     * parametros de tipo string para ser usados como destinatarios
     * ejemplos: 
     *     ```php
     *     destinatarios('mery@sd.s')
     *     destinatarios('mery@sd.s', 'astrid@200.12.23.5', ... )
     *     destinatarios(array(
     *         'mery@sd.s',
     *         'astrid@200.12.23.5', 
     *         ... 
     *      ))
     *      ```
     *
     * @param mixed $dest,... un arreglo de destinatarios o varios parametros con los destianatarios
     * 
     * @return self
     */
    public function to($param)
    {
        $emailArray = is_array($param) ? $param : func_get_args();

        foreach ($emailArray as &$email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('to(): \''. $email . '\' no es una direccion email valida');
            }
        }

        $this->to = $emailArray;

        return $this;
    }

    /**
     * Asignar el asunto (subject) al email a ser enviado
     *
     * @param string $asunto
     *
     * @return self
     */
    public function subject($txt)
    {
        $this->subject = (string)$txt;
        return $this;
    }

    /**
     * Asignar una plantilla para ser usada como texto del email
     *
     * Si se realizan llamadas subsecuentes a plantilla() o cuerpo()
     * solo tendra efecto la ultima que se realice
     * 
     * @see \boomsolutions\Email::cuerpo() para asignar un string cualquiera al
     *      cuerpo del email
     * 
     * @param string $template Direccion de la plantilla a renderizar, relativo al
     *        directorio de plantillas, que por defecto es 'twigTemplates/'
     * @param array $templateVars Opcional, Arreglo de variables pasadas
     *        a la plantilla
     * @param string $templatesPath Opcional, directorio donde buscar
     *        la plantilla a renderizar
     *
     * @return self
     */
    public function setBodyFromTemplate($template, $templateVars=array(), $templatesPath='templates/')
    {
        $this->twigLoader = new Twig_Loader_Filesystem();
        $this->twigLoader->addPath($templatesPath);

        $this->twigObject = new Twig_Environment($this->twigLoader);
        $this->twigObject->setCache($templatesPath.'cache/');
        // $this->twigObject->enableAutoReload();

        // renderizar la plantilla con sus variables y asignarla como cuerpo del email
        $this->setBody(
            $this->twigObject->load($template)->render($templateVars),
            'text/html'
        );

        return $this;
    }


    /**
     * Asignar el cuerpo del email a ser enviado
     *
     * Si se realizan llamadas subsecuentes a plantilla() o cuerpo() solo tendra
     * efecto la ultima que se realice
     *
     * @see \boomsolutions\Email::plantilla() para renderizan una plantilla Twig como
     *      cuerpo del email
     *
     * @param string $texto un string que se usara como cuerpo del mensaje
     * @param string $tipo MIMEtype del texto usado como cuerpo del mensaje
     *
     * @return self
     */
    public function setBody($texto, $tipo='text/html')
    {
        $this->messageBody = $texto;
        $this->messageType = $tipo;
        return $this;
    }

    /**
     * Agrega un archivo como adjunto al Email
     *
     * esta funcion debe llamarse varias veces por cada archivo que se desee adjuntar
     *
     * @param string $filePath URL del archivo a adjuntar
     * @param string $mimeType MIME type del archivo, puede ser obviado para archivos
     *                         comunes como imagenes, PDFs o archivos comprimidos, 
     *
     * @return self
     */
    public function attach($filePath, $contentType=null)
    {
        $this->attachments[] =  \Swift_Attachment::fromPath($filePath,$contentType);
        return $this;
    }

    /**
     * Agregar al correo una imagen inline y retornar su URL
     *
     * Este metodo Agrega al correo la imagen recibida por parametros, y luego
     * retorna una CID que se puede usar para el atributo 'src' de una
     * imagen en HTML.
     * Estandar RFC para las URL CID: https://tools.ietf.org/html/rfc2392
     *
     * @param $filePath URL de la imagen a agregar al correo
     *
     * @return string URL con esquema CID que debe usarse para referenciar
     * la imagen desde el cuerpo del email
     */
    public function inlineImageCID($filePath,$name=false)
    {
        if (!file_exists($filePath)) {
            throw new Exception("Error Processing Request", 1);
        }

        if (!$name) {
            $name = array_pop(explode(DIRECTORY_SEPARATOR, $filePath));
        }
        
        $this->inlineImages[$name] = $this->messageObject->embed(\Swift_EmbeddedFile::fromPath($filePath));
        
        return $this;
    }

    /**
     * Enviar el Email
     *
     * @return int Numero de destinatarios exitosos. Si no se pudo enviar a
     *             ninguno de los destinatarios retorna 0
     */
    public function send()
    {
        $this->messageObject
            ->setTo($this->to)
            ->setFrom($this->username)
            ->setSubject($this->subject)
            ->setBody($this->messageBody, $this->messageType);
        
        foreach ($this->attachments as $attachment) {
            $this->messageObject->attach($attachment);
        }

        return $this->swiftmailer->send($this->messageObject);
    }

    /**
     * Retorna una instancia al transport de swiftmailer especidicado en sus parametros
     *
     * @param int $transport     tipo de transport del que se quiere una instancia
     * @param string $user       usuario para ser usando en caso de que $transport sea
     *                           una instancia de \Swift_SmtpTransport
     * @param string $pass       contrasena para ser usanda en caso de que $transport
     *                           sea una instancia de \Swift_SmtpTransport
     * @param string $smtpServer direccion del servidor SMTP a usar
     * @param string $port       puerto a usar para conectarse a $smtpServer
     *
     * @return \Swift_Transport
     */
    protected static function newTransportInstance($transport=self::TRANSPORT_SENDMAIL, $user='', $pass='', $smtpServer='smtp.gmail.com', $port = 465)
    {
        if ($transport == self::TRANSPORT_SMTP) {

            return \Swift_SmtpTransport::newInstance($smtpServer, $port, 'ssl')
                        ->setUsername($user)
                        ->setPassword($pass);

        } elseif ($transport == self::TRANSPORT_SENDMAIL) {          
            
            return \Swift_SendmailTransport::newInstance('/usr/sbin/sendmail -bs');

        } else {
            
            return \Swift_MailTransport::newInstance();

        }
    }

    public function __get($name)
    {
        if ($name == 'password') {
            throw new RuntimeException('The \'password\' property can not be read', 1);
        }
        return $this->$name;
    }
}
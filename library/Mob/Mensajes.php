<?php

class Vendetta_Mensajes {

    protected $mensajes;

    public function __construct($id_usuario) {
        $this->_id_usuario = $id_usuario;
    }

    public function insert($para, $asunto, $mensaje, $fecha_enviado = "", $de = false) {
        $data = array(
            'remitente' => $de === false ? (int) $this->_id_usuario : (int) $de,
            'destinatario' => (int) $para,
            'mensaje' => $mensaje,
            'asunto' => $asunto,
            'fecha_enviado' => empty($fecha_enviado) ? date('Y-m-d H:i:s') : $fecha_enviado,
        );

        // La tabla real en vendetta_plus_old.sql es mob_mensajes
        return Zend_Registry::get('dbAdapter')->insert('mob_mensajes', $data);
    }
    
    public function getMensajes($tipo) {
        // $tipo = enviados | recibidos
        $columnByType = array('enviados' => 'remitente', 'recibidos' => 'destinatario');
        $deletedByType = array('enviados' => 'borrado_rem', 'recibidos' => 'borrado_dest');

        if (!isset($columnByType[$tipo])) {
            return array();
        }

        $db = Zend_Registry::get('dbAdapter');
        $select = $db->select()
            ->from('mob_mensajes', '*')
            ->where($columnByType[$tipo] . ' = ?', (int) $this->_id_usuario)
            ->where($deletedByType[$tipo] . ' = 0')
            ->order('fecha_enviado DESC');

        return $db->fetchAll($select);
    }
    
    public function getTipo($id) {
        // para saber si un mensaje fue enviado o recibido
        $db = Zend_Registry::get('dbAdapter');
        $select = $db->select()
            ->from('mob_mensajes', array('remitente', 'destinatario'))
            ->where('id_mensaje = ?', (int) $id)
            ->limit(1);

        $res = $db->fetchAll($select);
        if (empty($res)) return false;

        if ((int) $res[0]['destinatario'] === (int) $this->_id_usuario) return 'destinatario';
        if ( (int) $res[0]['remitente'] === (int) $this->_id_usuario) return 'remitente';
        return false;
    }
    
    public function borrar($id) {
        $tipo = $this->getTipo((int) $id);
        if ($tipo === false) return false;

        $campo = sprintf('borrado_%s', $tipo == 'destinatario' ? 'dest' : 'rem');
        $update = array($campo => 1);
        return Zend_Registry::get('dbAdapter')->update('mob_mensajes', $update, 'id_mensaje = '.(int)$id);
    }

}
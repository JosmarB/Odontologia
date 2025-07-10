<?php
// error_handler.php

class ErrorHandler {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function logError($level, $message, $file = null, $line = null, $trace = null, $additionalData = null) {
        try {
            // Manejo seguro del usuario en sesión
            $usuario_id = null;
            if (isset($_SESSION['usuario'])) {
                if (is_object($_SESSION['usuario'])) {
                    $usuario_id = $_SESSION['usuario']->id ?? null;
                } elseif (is_array($_SESSION['usuario'])) {
                    $usuario_id = $_SESSION['usuario']['id'] ?? null;
                }
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            // Convertir traza a string si es un array
            $traceStr = $trace;
            if (is_array($trace)) {
                $traceStr = json_encode($trace);
            }
            
            // Convertir datos adicionales a JSON si es un array u objeto
            $additionalDataStr = null;
            if ($additionalData !== null) {
                if (is_array($additionalData) || is_object($additionalData)) {
                    $additionalDataStr = json_encode($additionalData);
                } else {
                    $additionalDataStr = (string)$additionalData;
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO errores_sistema 
                (usuario_id, nivel, mensaje, archivo, linea, traza, datos_adicionales, ip, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $usuario_id,
                $level,
                $message,
                $file,
                $line,
                $traceStr,
                $additionalDataStr,
                $ip,
                $userAgent
            ]);
            
            return true;
        } catch (Exception $e) {
            // Si falla el registro en la base de datos, guardar en archivo de log
            error_log("[" . date('Y-m-d H:i:s') . "] Error al registrar en BD: " . $e->getMessage() . 
                     "\nError original: [$level] $message in $file:$line\nTrace: " . 
                     (is_array($trace) ? json_encode($trace) : $trace) . "\n", 
                     3, __DIR__ . '/../logs/system_errors.log');
            return false;
        }
    }
    
    public function registerHandlers() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function handleError($errno, $errstr, $errfile, $errline) {
        $levels = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'ERROR',
            E_NOTICE => 'INFO',
            E_CORE_ERROR => 'ERROR',
            E_CORE_WARNING => 'WARNING',
            E_COMPILE_ERROR => 'ERROR',
            E_COMPILE_WARNING => 'WARNING',
            E_USER_ERROR => 'ERROR',
            E_USER_WARNING => 'WARNING',
            E_USER_NOTICE => 'INFO',
            E_STRICT => 'INFO',
            E_RECOVERABLE_ERROR => 'ERROR',
            E_DEPRECATED => 'WARNING',
            E_USER_DEPRECATED => 'WARNING'
        ];
        
        $level = $levels[$errno] ?? 'ERROR';
        $this->logError($level, $errstr, $errfile, $errline, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        
        // Ejecutar el gestor de errores interno de PHP
        return false;
    }
    
    public function handleException(Throwable $exception) {
        $this->logError(
            'ERROR',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
            ['exception' => get_class($exception)]
        );
        
        // Mostrar error genérico al usuario
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Ocurrió un error interno. El equipo técnico ha sido notificado.']);
        exit;
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logError(
                'CRITICAL',
                $error['message'],
                $error['file'],
                $error['line'],
                null
            );
        }
    }
    
    public function getErrors($limit = 100, $level = null, $search = null) {
        $query = "SELECT e.*, u.nombre as usuario_nombre 
                  FROM errores_sistema e
                  LEFT JOIN usuario u ON e.usuario_id = u.id
                  WHERE 1=1";
        
        $params = [];
        
        if ($level) {
            $query .= " AND e.nivel = :nivel";
            $params[':nivel'] = $level;
        }
        
        if ($search) {
            $query .= " AND (e.mensaje LIKE :search OR e.archivo LIKE :search_file OR u.nombre LIKE :search_user)";
            $params[':search'] = "%$search%";
            $params[':search_file'] = "%$search%";
            $params[':search_user'] = "%$search%";
        }
        
        $query .= " ORDER BY e.fecha DESC LIMIT :limit";
        $params[':limit'] = (int)$limit;
        
        $stmt = $this->db->prepare($query);
        
        // Vincular parámetros manualmente para el LIMIT
        foreach ($params as $key => $value) {
            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $paramType);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function clearOldErrors($days = 30) {
        $stmt = $this->db->prepare("DELETE FROM errores_sistema WHERE fecha < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
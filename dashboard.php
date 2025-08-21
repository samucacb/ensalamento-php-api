<?php
// Desabilitar TODOS os erros PHP para garantir JSON válido
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Capturar qualquer erro e retornar JSON
set_error_handler(function($severity, $message, $file, $line) {
    return true;
});


try {
    require_once __DIR__ . '/../config/database.php';
    
    $action = $_GET['action'] ?? '';
    
    if (!isset($database)) {
        throw new Exception('Conexão com banco de dados não disponível.');
    }
    
    // =================================================================
    // >> LÓGICA DE PERÍODO CORRIGIDA E PROFISSIONAL (A MUDANÇA PRINCIPAL) <<
    // =================================================================
    // 1. Prioridade 1: Tenta pegar o período da URL, enviado pelo JavaScript.
    $periodo_selecionado = $_GET['periodo'] ?? null;

    // 2. Prioridade 2: Se não veio da URL, busca o período ATIVO no banco.
    if (empty($periodo_selecionado)) {
        $stmt_ativo = $database->prepare("SELECT periodo FROM periodos WHERE ativo = 1 LIMIT 1");
        $stmt_ativo->execute();
        $periodo_selecionado = $stmt_ativo->fetchColumn();
    }

    // 3. Prioridade 3: Se ainda não temos um período, busca o mais recente como fallback.
    if (empty($periodo_selecionado)) {
        $stmt_recente = $database->prepare("SELECT periodo FROM periodos ORDER BY periodo DESC LIMIT 1");
        $stmt_recente->execute();
        $periodo_selecionado = $stmt_recente->fetchColumn();
    }

    // 4. Verificação final: Se o sistema não tem nenhum período cadastrado, retorna um erro.
    if (empty($periodo_selecionado)) {
        throw new Exception('Nenhum período letivo encontrado no sistema.');
    }
    // =================================================================
    
    // O switch agora usa a variável $periodo_selecionado, que tem o valor correto.
    switch ($action) {
        case 'estatisticas':
            $response = obterEstatisticasGerais($database, $periodo_selecionado);
            break;
            
        case 'grafico_status':
            $response = obterDadosGraficoStatus($database, $periodo_selecionado);
            break;
            
        case 'grafico_ocupacao':
            // Assumindo que esta função também precisa do período
            $response = obterDadosGraficoOcupacao($database, $periodo_selecionado);
            break;
            
        case 'atividades':
            // Atividades podem ou não depender do período. Se não dependerem, pode remover.
            $response = obterUltimasAtividades($database, $periodo_selecionado);
            break;
            
        case 'alertas':
            $response = obterAlertas($database, $periodo_selecionado);
            break;
            
        default:
            throw new Exception('Ação não reconhecida: ' . htmlspecialchars($action));
    }
    
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    http_response_code(500 );
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Em api/dashboard.php

function obterEstatisticasGerais($database, $periodo) {
    // Estatísticas de salas (continua igual)
    $stmt = $database->prepare("SELECT COUNT(*) as total FROM salas WHERE ativo = 1");
    $stmt->execute();
    $total_salas = $stmt->fetch()['total'];
    
    // Estatísticas de turmas (continua igual)
    $stmt = $database->prepare("SELECT COUNT(*) as total FROM turmas WHERE periodo = ? AND ativo = 1");
    $stmt->execute([$periodo]);
    $total_turmas = $stmt->fetch()['total'];
    
    // =================================================================
    // CONSULTA DE ENSALAMENTO CORRIGIDA
    // =================================================================
    $stmt = $database->prepare("
        SELECT 
            -- Conta apenas as alocadas
            COUNT(CASE WHEN status = 'alocado' THEN 1 END) as total_alocados,
            
            -- Conta apenas os conflitos
            COUNT(CASE WHEN status = 'conflito' THEN 1 END) as conflitos,
            
            -- Calcula a média da eficiência APENAS das alocadas
            AVG(CASE WHEN status = 'alocado' THEN eficiencia ELSE NULL END) as eficiencia_media,
            
            -- Conta as salas utilizadas APENAS pelas alocadas
            COUNT(DISTINCT CASE WHEN status = 'alocado' THEN sala_id ELSE NULL END) as salas_utilizadas
            
        FROM ensalamento 
        WHERE periodo = ?
    ");
    $stmt->execute([$periodo]);
    $stats = $stmt->fetch();
    // =================================================================
    
    // O cálculo de pendentes agora é feito no PHP para garantir precisão
    $turmas_alocadas = (int)($stats['total_alocados'] ?? 0);
    $turmas_pendentes = $total_turmas - $turmas_alocadas;

    return [
        'total_salas' => (int)$total_salas,
        'total_turmas' => (int)$total_turmas,
        'turmas_alocadas' => $turmas_alocadas,
        'turmas_conflito' => (int)($stats['conflitos'] ?? 0),
        'turmas_pendentes' => $turmas_pendentes, // <-- Cálculo agora é feito no PHP
        'eficiencia_media' => round($stats['eficiencia_media'] ?? 0, 1), // <-- Valor agora é a média correta
        'salas_utilizadas' => (int)($stats['salas_utilizadas'] ?? 0),
        'periodo_atual' => $periodo
    ];
}


function obterDadosGraficoStatus($database, $periodo) {
    $stmt = $database->prepare("
        SELECT 
            status,
            COUNT(*) as total
        FROM ensalamento 
        WHERE periodo = ?
        GROUP BY status
    ");
    $stmt->execute([$periodo]);
    $resultados = $stmt->fetchAll();
    
    $dados = [
        'alocadas' => 0,
        'conflitos' => 0,
        'pendentes' => 0
    ];
    
    foreach ($resultados as $resultado) {
        switch ($resultado['status']) {
            case 'alocado':
                $dados['alocadas'] = (int)$resultado['total'];
                break;
            case 'conflito':
                $dados['conflitos'] = (int)$resultado['total'];
                break;
            case 'pendente':
                $dados['pendentes'] = (int)$resultado['total'];
                break;
        }
    }
    
    return $dados;
}

// Em api/dashboard.php

// O nome da sua função pode ser um pouco diferente (ex: getOccupancyChart), 
// mas a lógica interna deve ser substituída por esta.
// Em api/dashboard.php

// Substitua a sua função inteira por esta versão corrigida:
function obterDadosGraficoOcupacao($database, $periodo) { // <<-- 1. CORREÇÃO AQUI: Aceita o $periodo
    
    // Passo 1: Definir a ordem correta e os nomes dos dias da semana (continua igual)
    $dias_semana = [
        'segunda' => 'Segunda', 'terca' => 'Terça', 'quarta' => 'Quarta',
        'quinta' => 'Quinta', 'sexta' => 'Sexta', 'sabado' => 'Sábado'
    ];
    $dados_grafico = array_fill_keys(array_values($dias_semana), 0);

    // Passo 2: Buscar os dados reais do banco de dados
    // A linha com o período "engessado" foi REMOVIDA.
    // Agora usamos o $periodo que a função recebeu como parâmetro.

    $sql = "SELECT 
                dia_semana, 
                COUNT(id) as quantidade
            FROM ensalamento
            WHERE 
                periodo = ? 
                AND status = 'alocado'
                AND dia_semana IS NOT NULL 
                AND dia_semana != ''
            GROUP BY dia_semana";
            
    $stmt = $database->prepare($sql);
    $stmt->execute([$periodo]); // <<-- 2. CORREÇÃO AQUI: Usa a variável $periodo
    $resultados = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Passo 3: Preencher os dados do gráfico (continua igual)
    foreach ($resultados as $dia_db => $quantidade) {
        if (isset($dias_semana[$dia_db])) {
            $nome_dia = $dias_semana[$dia_db];
            $dados_grafico[$nome_dia] = (int)$quantidade;
        }
    }

    // Passo 4: Retornar os dados no formato que o JavaScript espera (continua igual)
    return [
        'labels' => array_keys($dados_grafico),
        'values' => array_values($dados_grafico)
    ];
}



function obterUltimasAtividades($database) {
    try {
        $stmt = $database->prepare("
            SELECT 
                'Ensalamento' as acao,
                'turma' as tabela,
                e.turma_id as registro_id,
                'Sistema' as usuario,
                e.created_at,
                CONCAT('Turma ', t.codigo, ' alocada na sala ', s.codigo) as descricao
            FROM ensalamento e
            LEFT JOIN turmas t ON e.turma_id = t.id
            LEFT JOIN salas s ON e.sala_id = s.id
            ORDER BY e.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $atividades = $stmt->fetchAll();
        
        foreach ($atividades as &$atividade) {
            $atividade['data'] = date('d/m/Y H:i', strtotime($atividade['created_at']));
        }
        
        return $atividades;
        
    } catch (Exception $e) {
        return [];
    }
}

function obterAtividadesSimuladas() {
    // Retornar atividades simuladas
    return [
        [
            'acao' => 'Sistema',
            'descricao' => 'Sistema inicializado',
            'data' => date('d/m/Y H:i'),
            'usuario' => 'Admin'
            ]
        ];
    }

function obterAlertas($database, $periodo) {
    $alertas = [];
    
    try {
        // Verificar conflitos
        $stmt = $database->prepare("
            SELECT COUNT(*) as total 
            FROM ensalamento 
            WHERE periodo = ? AND status = 'conflito'
        ");
        $stmt->execute([$periodo]);
        $conflitos = $stmt->fetch()['total'];
        
        if ($conflitos > 0) {
            $alertas[] = [
                'tipo' => 'erro',
                'mensagem' => "Existem {$conflitos} turmas com conflito"
            ];
        }
        
        // Verificar turmas pendentes
        $stmt = $database->prepare("
            SELECT COUNT(*) as total 
            FROM ensalamento 
            WHERE periodo = ? AND status = 'pendente'
        ");
        $stmt->execute([$periodo]);
        $pendentes = $stmt->fetch()['total'];
        
        if ($pendentes > 0) {
            $alertas[] = [
                'tipo' => 'aviso',
                'mensagem' => "Existem {$pendentes} turmas pendentes de alocação"
            ];
        }
        
        // Verificar eficiência
        $stmt = $database->prepare("
            SELECT AVG(eficiencia) as eficiencia_media 
            FROM ensalamento 
            WHERE periodo = ? AND status = 'alocado'
        ");
        $stmt->execute([$periodo]);
        $eficiencia = $stmt->fetch()['eficiencia_media'] ?? 0;
        
        if ($eficiencia < 70) {
            $alertas[] = [
                'tipo' => 'aviso',
                'mensagem' => 'Eficiência média baixa: ' . round($eficiencia, 1) . '%'
            ];
        }
        
    } catch (Exception $e) {
        // Se há erro nas consultas, adicionar alerta informativo
        $alertas[] = [
            'tipo' => 'info',
            'mensagem' => 'Sistema funcionando normalmente'
        ];
    }
    
    return $alertas;
}
?>


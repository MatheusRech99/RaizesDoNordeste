<?php

namespace App\Application\Clientes;

use App\Domain\Auditoria\Acao;
use App\Domain\Usuarios\Consentimento;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class CadastrarCliente
{
    public function __construct(private AuditLogger $auditoria)
    {
    }

    public function executar(array $dados, string $ip): Usuario
    {
        return DB::transaction(function () use ($dados, $ip) {
            $cliente = Usuario::create([
                'nome' => $dados['nome'],
                'email' => $dados['email'],
                'senha' => $dados['senha'],
                'perfil' => Perfil::CLIENTE,
                'telefone' => $dados['telefone'] ?? null,
                'data_nascimento' => $dados['dataNascimento'] ?? null,
            ]);

            // os termos sempre entram aceitos (a validacao ja barrou o resto)
            $escolhas = [
                Finalidade::TERMOS->value => true,
                Finalidade::FIDELIDADE->value => $dados['consentimentoFidelidade'] ?? false,
                Finalidade::MARKETING->value => $dados['consentimentoMarketing'] ?? false,
            ];

            foreach ($escolhas as $finalidade => $aceito) {
                Consentimento::create([
                    'usuario_id' => $cliente->id,
                    'finalidade' => $finalidade,
                    'aceito' => $aceito,
                    'versao_termo' => config('lgpd.versao_termo'),
                    'ip' => $ip,
                ]);
            }

            $this->auditoria->registrar(Acao::CADASTRO_CLIENTE, $cliente, null, ['consentimentos' => $escolhas], $cliente->id);

            return $cliente->load('consentimentos');
        });
    }
}

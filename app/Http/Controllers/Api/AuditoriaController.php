<?php

namespace App\Http\Controllers\Api;

use App\Domain\Auditoria\RegistroAuditoria;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListarAuditoriaRequest;
use App\Http\Resources\AuditoriaResource;

class AuditoriaController extends Controller
{
    // RF28: so a matriz consulta a trilha
    /**
     * Consulta a trilha de auditoria
     *
     * Só ADMIN. Do mais novo para o mais antigo. Não há rota para alterar ou apagar registros.
     */
    public function index(ListarAuditoriaRequest $request)
    {
        $lista = RegistroAuditoria::query()
            ->when($request->query('acao'), fn ($q, $v) => $q->where('acao', $v))
            ->when($request->query('entidade'), fn ($q, $v) => $q->where('entidade', $v))
            ->when($request->query('entidadeId'), fn ($q, $v) => $q->where('entidade_id', $v))
            ->when($request->query('usuarioId'), fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($request->query('de'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('ate'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($request->limite());

        return AuditoriaResource::collection($lista);
    }
}

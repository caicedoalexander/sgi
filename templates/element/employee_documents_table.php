<?php
/**
 * Tabla de documentos de un empleado (usada tanto en la carpeta raíz
 * como en subcarpetas dentro de Employees/view).
 *
 * @var \App\View\AppView $this
 * @var iterable $docs        Documentos a listar.
 * @var int     $employeeId   ID del empleado (para construir URLs).
 * @var bool    $canDelete    Si el usuario puede eliminar documentos.
 * @var bool    $showHeader   (opcional, default true) Renderizar <thead> con etiquetas.
 */

$showHeader = $showHeader ?? true;
?>
<div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
        <?php if ($showHeader): ?>
        <thead>
            <tr>
                <th>Documento</th>
                <th>Tipo</th>
                <th>Tamaño</th>
                <th>Subido por</th>
                <th>Fecha</th>
                <th class="text-end">Acciones</th>
            </tr>
        </thead>
        <?php endif; ?>
        <tbody>
            <?php foreach ($docs as $doc):
                $type = $this->DocumentIcon->typeLabel($doc->mime_type);
            ?>
            <tr>
                <td>
                    <i class="bi <?= h($this->DocumentIcon->iconClass($doc->mime_type)) ?> me-1"
                       style="color:<?= h($this->DocumentIcon->iconColor($doc->mime_type)) ?>;font-size:1rem;vertical-align:middle"></i>
                    <?= $this->Html->link(
                        h($doc->name),
                        ['action' => 'downloadDocument', $employeeId, $doc->id],
                        ['target' => '_blank', 'class' => 'text-decoration-none']
                    ) ?>
                </td>
                <td><span class="pill <?= h($this->DocumentIcon->badgeClass($type)) ?>"><?= h($type) ?></span></td>
                <td style="color:#888;font-size:.8rem"><?= $doc->file_size ? $this->Number->toReadableSize($doc->file_size) : '—' ?></td>
                <td style="color:#888;font-size:.8rem"><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></td>
                <td style="color:#888;font-size:.8rem">
                    <?= $doc->created?->format('d/m/Y') ?>
                    <span style="color:#bbb;font-size:.7rem;display:block"><?= $doc->created?->format('H:i') ?></span>
                </td>
                <td class="text-end">
                    <div class="d-flex gap-1 justify-content-end">
                        <?= $this->Html->link(
                            '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>',
                            ['action' => 'downloadDocument', $employeeId, $doc->id],
                            ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']
                        ) ?>
                        <?php if ($canDelete): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash" aria-hidden="true"></i>',
                            ['action' => 'deleteDocument', $employeeId, $doc->id],
                            ['confirm' => '¿Eliminar este documento?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']
                        ) ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

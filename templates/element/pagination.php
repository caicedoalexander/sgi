<div class="card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}} - {{end}} de {{count}}') ?></small>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?= $this->Paginator->first('«', [
                'templates' => [
                    'first' => '<li class="page-item"><a class="page-link" href="{{url}}">{{text}}</a></li>',
                ],
            ]) ?>
            <?= $this->Paginator->prev('‹', [
                'templates' => [
                    'prevActive'   => '<li class="page-item"><a class="page-link" rel="prev" href="{{url}}">{{text}}</a></li>',
                    'prevDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                ],
            ]) ?>
            <?= $this->Paginator->numbers([
                'templates' => [
                    'number'   => '<li class="page-item"><a class="page-link" href="{{url}}">{{text}}</a></li>',
                    'current'  => '<li class="page-item active" aria-current="page"><span class="page-link">{{text}}</span></li>',
                    'ellipsis' => '<li class="page-item disabled"><span class="page-link">…</span></li>',
                ],
            ]) ?>
            <?= $this->Paginator->next('›', [
                'templates' => [
                    'nextActive'   => '<li class="page-item"><a class="page-link" rel="next" href="{{url}}">{{text}}</a></li>',
                    'nextDisabled' => '<li class="page-item disabled"><span class="page-link">{{text}}</span></li>',
                ],
            ]) ?>
            <?= $this->Paginator->last('»', [
                'templates' => [
                    'last' => '<li class="page-item"><a class="page-link" href="{{url}}">{{text}}</a></li>',
                ],
            ]) ?>
        </ul>
    </nav>
</div>

@extends('admin.layout')

@section('title', "変更 #{$change->id}")

@section('content')
    <p><a href="{{ route('admin.code-changes.index') }}">&larr; 変更履歴</a></p>

    <h1>変更 #{{ $change->id }} <span class="badge badge-{{ $change->status }}">{{ $change->status }}</span></h1>

    <dl>
        <dt>ファイル</dt>
        <dd><code>{{ $change->path }}</code> ({{ $change->language }})</dd>
        <dt>日時</dt>
        <dd>{{ $change->created_at->format('Y-m-d H:i:s') }}</dd>
        <dt>理由</dt>
        <dd>{{ $change->reason }}</dd>
        @if ($change->symbols_added)
            <dt>追加シンボル</dt>
            <dd>{{ implode(', ', $change->symbols_added) }}</dd>
        @endif
        @if ($change->symbols_removed)
            <dt>削除シンボル</dt>
            <dd>{{ implode(', ', $change->symbols_removed) }}</dd>
        @endif
    </dl>

    @if ($change->ast_errors)
        <h2>AST エラー（適用されませんでした）</h2>
        <ul>
            @foreach ($change->ast_errors as $error)
                <li>line {{ $error['line'] }}, col {{ $error['column'] }}: {{ $error['kind'] }} — <code>{{ $error['snippet'] }}</code></li>
            @endforeach
        </ul>
    @endif

    <h2>差分</h2>
    <div class="diff">
        @foreach (explode("\n", $change->diff) as $line)
            <div @class([
                'add' => str_starts_with($line, '+') && ! str_starts_with($line, '+++'),
                'del' => str_starts_with($line, '-') && ! str_starts_with($line, '---'),
                'hunk' => str_starts_with($line, '@@'),
            ])>{{ $line === '' ? ' ' : $line }}</div>
        @endforeach
    </div>
@endsection

@extends('admin.layout')

@section('title', '変更履歴')

@section('content')
    <h1>変更履歴</h1>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>日時</th>
                <th>状態</th>
                <th>ファイル</th>
                <th>理由</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($changes as $change)
                <tr>
                    <td><a href="{{ route('admin.code-changes.show', $change) }}">{{ $change->id }}</a></td>
                    <td>{{ $change->created_at->format('Y-m-d H:i:s') }}</td>
                    <td><span class="badge badge-{{ $change->status }}">{{ $change->status }}</span></td>
                    <td><code>{{ $change->path }}</code></td>
                    <td>{{ str($change->reason)->limit(80) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">まだ変更はありません。</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $changes->links() }}
@endsection

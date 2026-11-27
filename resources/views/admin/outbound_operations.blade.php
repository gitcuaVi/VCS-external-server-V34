@extends('admin.layout')

@section('title', 'Đồng bộ Freshdesk')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Đồng bộ Freshdesk</h1>
        <p class="text-xs text-slate-500 mt-1">
            Các thao tác server gửi lên Freshdesk. Thao tác <strong>failed</strong> đã ngừng gửi lại:
            "Sync lại ticket" tạo một lần đồng bộ SLA mới; "Chạy lại" tạo bản sao của lệnh đổi Due Date / Group.
        </p>
    </div>

    <div class="bg-white border border-slate-200/80 rounded-xl p-4 shadow-xs">
        <form action="{{ route('admin.outbound.index') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Trạng thái</label>
                <select name="state" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
                    @foreach($states as $option)
                        <option value="{{ $option }}" @selected($state === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Ticket ID</label>
                <input type="number" min="1" name="ticket_id" value="{{ $ticketId }}" placeholder="VD: 90388"
                    class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500 font-medium">
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 bg-sky-600 hover:bg-sky-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors shadow-xs">
                    <i class="fa-solid fa-filter mr-1"></i> Lọc
                </button>
                <a href="{{ route('admin.outbound.index') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold px-3 py-2 rounded-lg border border-slate-200 transition-colors">
                    Đặt lại
                </a>
            </div>
        </form>
    </div>

    <div class="bg-white border border-slate-200/80 rounded-xl shadow-xs overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-slate-50 text-left text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3">Ticket</th>
                    <th class="px-4 py-3">Loại</th>
                    <th class="px-4 py-3">Lỗi gần nhất</th>
                    <th class="px-4 py-3 whitespace-nowrap">Tạo lúc</th>
                    <th class="px-4 py-3 whitespace-nowrap">Cập nhật</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($operations as $op)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-mono font-semibold text-slate-900">{{ $op->ticket_id }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ $op->operation_type }}</td>
                        <td class="px-4 py-3 text-slate-600 max-w-md break-words" title="{{ $op->last_error }}">{{ \Illuminate\Support\Str::limit((string) $op->last_error, 300) }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $op->created_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ $op->updated_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($op->state === 'failed' && $op->operation_type === 'sync_sla')
                                <form method="POST" action="{{ route('admin.outbound.resync', $op->ticket_id) }}"
                                    onsubmit="return confirm('Sync lại ticket {{ $op->ticket_id }} lên Freshdesk?')">
                                    @csrf
                                    <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white font-semibold px-3 py-1.5 rounded-lg transition-colors">
                                        <i class="fa-solid fa-rotate mr-1"></i> Sync lại ticket
                                    </button>
                                </form>
                            @elseif(isset($retryableIds[$op->operation_id]))
                                <form method="POST" action="{{ route('admin.outbound.retry', $op->operation_id) }}"
                                    onsubmit="return confirm('Chạy lại {{ $op->operation_type }} cho ticket {{ $op->ticket_id }}?')">
                                    @csrf
                                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-semibold px-3 py-1.5 rounded-lg transition-colors">
                                        <i class="fa-solid fa-play mr-1"></i> Chạy lại
                                    </button>
                                </form>
                            @elseif($op->state === 'failed' && in_array($op->operation_type, $retryableCommands, true))
                                <span class="text-[11px] text-slate-400" title="Ticket đã có lệnh cùng loại mới hơn">Đã có lệnh mới hơn</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">Không có thao tác nào.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $operations->links() }}</div>
</div>
@endsection

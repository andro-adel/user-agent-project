<x-filament-panels::page>
<div class="space-y-4">
    <textarea class="w-full h-64 p-2 border" readonly>{{ $conversation }}</textarea>
    <textarea wire:model="input" class="w-full p-2 border" placeholder="اكتب هنا..."></textarea>
    <button wire:click="send" class="px-4 py-2 bg-primary text-white">إرسال</button>
</div>
</x-filament-panels::page>

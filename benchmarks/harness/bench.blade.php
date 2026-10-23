<!doctype html>
<html><head><title>Bench</title></head>
<body>
<ul>
@foreach ($items as $item)
    <li class="item-{{ $item }}">Item {{ $item }} &mdash; {{ $item * 3 }}</li>
@endforeach
</ul>
</body></html>

@props(['id', 'name'])
{{-- A person inside a table row: the row stays the link; hovering the name peeks (PeekHost checks visibility). --}}
<span class="pos-person-inline" data-person="{{ (int) $id }}"><x-pos.avatar :name="$name" size="xs" /><span class="pos-person-inline-name">{{ $name }}</span></span>

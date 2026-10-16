{{-- The favourite star in the corner of a cover. Put it inside a `relative` box. --}}
<span {{ $attributes->merge(['class' => 'absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-black/60 text-fav-soft flex items-center justify-center text-[10px]', 'title' => 'Favourite']) }}>
    <i class="fa-solid fa-star"></i>
</span>

@extends('layouts.app')

@section('content')

<div class="page-wrap">

    <div class="card" style="max-width:900px;margin:auto;">

        <h3 class="title mb-20">صور العقار</h3>

        <form method="POST"
              action="{{ route('admin.unit.photos.upload') }}"
              enctype="multipart/form-data"
              class="mb-40">
            @csrf

            <div class="form-group">
                <label class="label">
                    اختيار صور (PNG / JPG / WEBP)
                </label>
                <input type="file"
                       name="photos[]"
                       multiple
                       accept=".png,.jpg,.jpeg,.webp"
                       class="input">
            </div>

            <button class="btn" type="submit">
                رفع الصور
            </button>
        </form>

        @if($photos->count())
            <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:20px;">

                @foreach($photos as $photo)
                    <div class="card" style="padding:10px; text-align:center;">

                        <img src="{{ asset('storage/'.$photo->path) }}"
                             alt=""
                             style="width:100%; border-radius:10px; margin-bottom:10px;">

                        <form method="POST"
                              action="{{ route('admin.unit.photos.delete',$photo) }}">
                            @csrf
                            @method('DELETE')

                            <button class="btn btn-outline" type="submit">
                                حذف
                            </button>
                        </form>

                    </div>
                @endforeach

            </div>
        @endif

        <div style="margin-top:30px;">
            <a class="btn"
               href="{{ route('admin.license.form') }}">
                متابعة للتصريح
            </a>
        </div>

    </div>

</div>

@endsection
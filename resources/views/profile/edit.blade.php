@extends('layouts.ai-grader')

@section('title', __('ai-grader.admin.profile.title'))

@section('content')
    @component('ai-grader.partials.breadcrumb')
        @slot('li_1')
            @lang('ai-grader.admin.navigation.grader_ai')
        @endslot
        @slot('title')
            @lang('ai-grader.admin.profile.title')
        @endslot
    @endcomponent

    <div class="row">
        <div class="col-xl-8">
            @include('profile.partials.update-profile-information-form')
            @include('profile.partials.update-password-form')
        </div>
    </div>
@endsection

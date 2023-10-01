@extends('base')

@section('main')
    <div class="container" style="margin-top: 20px;">
        <div class="jumbotron">
            <p class="text text-info">You have successfully authenticated and can now use this to display your subscriber count using chatbots. Below is an example using the two common ones: Streamlabs Chatbot and Nightbot.</p>

            <h3>Streamlabs Chatbot:</h3>
            <p class="text text-muted">Add a command that includes the part below and it will be replaced with the subscriber count in the command:</p>
            <pre>$readapi({{ $route }})</pre>
            <p class="text text-muted">For example, if you wanted to display this message:</p>
            <pre>Current subscriber count: 123 - Thank you for your support!</pre>
            <p class="text text-muted">You would put this in the command response:</p>
            <pre>Current subscriber count: $readapi({{ $route }}) - Thank you for your support!</pre>

            <h3>Nightbot:</h3>
            <p class="text text-muted">Add a command that includes the part below and it will be replaced with the subscriber count in the command:</p>
            <pre>$(urlfetch {{ $route }})</pre>
            <p class="text text-muted">For example, if you wanted to display this message:</p>
            <pre>Current subscriber count: 123 - Thank you for your support!</pre>
            <p class="text text-muted">You would put this in the command response:</p>
            <pre>Current subscriber count: $(urlfetch {{ $route }}) - Thank you for your support!</pre>

            <p class="text text-info">If you wish to log out, you can do so below. This will not prevent any commands from working.</p>
            <a class="btn btn-danger" href="{{ route('auth.twitch.logout') }}">Log out</a>
        </div>
    </div>
@endsection

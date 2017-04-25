$(function () {
    var interval = setInterval(get_progress, 2000);
    function get_progress() {
        var action = '/converter/duration',
            method = 'GET',
            data = {file_name: '{{$guid}}'};
        $.ajax({
            url: action,
            type: method,
            data: data
        }).done(function (data) {
            if (data === 'error') {
                document.location.href = '/';
            } else {
                $('#bar').width(data + '%').html(data + '%');
                if (data === '100') {
                    document.location.href = '/converter/show/{{$guid}}';
                }
            }
        });
    }
});
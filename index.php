<?php

// TODO: look at // todo: https://shanetully.com/2014/09/a-dead-simple-webrtc-example/

// Session-cookies are used to generate an unique ID (at the server)
// to identify the user. This command starts the session for session cookies.
session_start();

// Websocket-hack: 'eventsource' in the URL is used to distinguish 
// the HTML form the real eventsource calls
if (!isset($_GET['eventSource'])) { // show HTML CSS and Javascript
    ?><!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1, maximum-scale=1">
        <style>
        body {
            margin: 0;
        }
        .videos {
            height: 100%;
            width: 100%;
        }
        #localVideo, #remoteVideo {
            height: 100%;
            max-height: 100%;
            max-width: 100%;
            object-fit: cover;
            width: 100%;
        }
        </style>
    </head>
    <body>
    <div class="videos">
        <video id="localVideo" autoplay></video>
        <video id="remoteVideo" autoplay style="display:none"></video>
    </div>
    <script type="text/javascript">

    var answer = 0;
    var pc=null
	var	localStream=null;
	var ws=null;

    // Websocket-hack: 'eventsource' parameter is used to distinguish 
    // the HTML form the real eventsource calls
    var URL = 'index.php?eventSource=yes';
    var localVideo = document.getElementById('localVideo');
    var remoteVideo = document.getElementById('remoteVideo');
    var configuration  = {
        'iceServers': [
			{'urls': 'stun:stun.stunprotocol.org:3478'},
			{'urls': 'stun:stun.l.google.com:19302'},
			//{'urls': 'stun:stun1.l.google.com:19302'},
			//{'urls': 'stun:stun2.l.google.com:19302'}
        ]
    };

	// Start
    navigator.mediaDevices.getUserMedia({
            //audio: true,
            video: true
        }).then(function (stream) {
            localVideo.srcObject = stream;
            localStream = stream;

			ws = new EventSource(URL);
            
            // Websocket-hack: EventSource does not have a 'send()'
            // so I use an ajax-xmlHttpRequest for posting data
			ws.send = function send(message) {
				 var xhttp = new XMLHttpRequest();
				 xhttp.onreadystatechange = function() {
					 if (this.readyState!=4) {
					   return;
					 }
					 if (this.status != 200) {
					   console.log("Error sending to "+url+ " with message: " +message);
					 }
				 };
				 xhttp.open("POST", URL, true);
				 xhttp.setRequestHeader("Content-Type","Application/X-Www-Form-Urlencoded");
				 xhttp.send(message);
			}


			// Websocket-hack: onmessage is extended for receiving 
            // multiple events at once for speed, because the polling 
            // frequency of EventSource is just once every few seconds.
			ws.onmessage = function(e) {
				if (e.data.includes("_MULTIPLEVENTS_")) {
					multiple = e.data.split("_MULTIPLEVENTS_");
					for (x=0;x<multiple.length;x++) {
						onsinglemessage(multiple[x]);
					}
				} else {
					onsinglemessage(e.data);
				}
			}

            // Go show myself
            localVideo.addEventListener('loadedmetadata', 
                function () {
                    publish('client-call', null)
                }
            );
			
        }).catch(function (e) {
            console.log("Problem while getting audio video stuff "+e);
        });
		
    
    function onsinglemessage(data) {
        var package = JSON.parse(data);
        var data = package.data;
        
        console.log("received single message: " + package.event);
        switch (package.event) {
            case 'client-call':
                icecandidate(localStream);
                pc.createOffer({
                    offerToReceiveAudio: 1,
                    offerToReceiveVideo: 1
                }).then(function (desc) {
                    pc.setLocalDescription(desc).then(
                        function () {
                            publish('client-offer', pc.localDescription);
                        }
                    ).catch(function (e) {
                        console.log("Problem with publishing client offer"+e);
                    });
                }).catch(function (e) {
                    console.log("Problem while doing client-call: "+e);
                });
                break;
            case 'client-answer':
                if (pc==null) {
                    console.error('Before processing the client-answer, I need a client-offer');
                }
                pc.setRemoteDescription(new RTCSessionDescription(data),function(){}, function(e){
                    console.log("Problem while doing client-answer: "+e);
                });
                break;
            case 'client-offer':
                icecandidate(localStream);
                pc.setRemoteDescription(new RTCSessionDescription(data), function(){
                    if (!answer) {
                        pc.createAnswer(function (desc) {
                                pc.setLocalDescription(desc, function () {
                                    publish('client-answer', pc.localDescription);
                                }, function(e){
                                    console.log("Problem getting client answer"+e);
                                });
                            }
                        ,function(e){
                            console.log("Problem while doing client-offer: "+e);
                        });
                        answer = 1;
                    }
                }, function(e){
                    console.log("Problem while doing client-offer2: "+e);
                });
                break;
            case 'client-candidate':
                pc.addIceCandidate(new RTCIceCandidate(data), function(){}, function(e){console.log("Problem adding ice candidate: "+e);});
                break;
        }
    };

    function icecandidate(localStream) {
        pc = new RTCPeerConnection(configuration);
        pc.onicecandidate = function (event) {
            if (event.candidate) {
                publish('client-candidate', event.candidate);
            }
        };
        try{
            pc.addStream(localStream);
        }catch(e){
            var tracks = localStream.getTracks();
            for(var i=0;i<tracks.length;i++){
                pc.addTrack(tracks[i], localStream);
            }
        }
        pc.ontrack = function (e) {
            document.getElementById('remoteVideo').style.display="block";
            document.getElementById('localVideo').style.display="none";
            remoteVideo.srcObject = e.streams[0];
        };
    }

    function publish(event, data) {
        console.log("sending ws.send: " + event);
        ws.send(JSON.stringify({
            event:event,
            data:data
        }));
    }


    </script>
    </body>
    </html>
<?php
} else if (count($_POST)!=0) { // simulated onmessage by ajax post

    // Websocket-hack: note that clients that connect with the same
	// session (like tabs in the same browser at the same computer)
	// will clash. This does never happen in practice, although when testing 
	// on one computer, you have to use two different browsers.
    $filename = '_file_'.session_id(); // .$_GET['room'];
    
    $posted=file_get_contents('php://input');

    // add the new message to file
	$file = fopen($filename,'ab');
    flock($file,LOCK_EX);
    if (filesize($filename)!=0) {
        fwrite($file,"_MULTIPLEVENTS_");
    }
  	fwrite($file,$posted);
	fflush($file);
	flock($file,LOCK_UN);
	fclose($file);

} else { // regular eventSource poll which is loaded every few seconds

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache'); // recommended

    function startsWith($haystack, $needle) {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    // get filelist
	$all = array ();
    $handle = opendir ( '../'.basename ( dirname ( __FILE__ ) ) );
    if ($handle !== false) {
        while ( false !== ($filename = readdir ( $handle )) ) {
            if (startsWith($filename,'_file_') && !(startsWith($filename,'_file_'.session_id()))) {
                $all [] .= $filename;
            }
        }
        closedir( $handle );
    }
	sort($all);
    
    // show and empty the first one that is not empty
	for($x = 0; $x < count ( $all ); $x ++) {
        $filename=$all[$x];
        if (filesize($filename)==0) {
            continue;
        }
        
        $tmp = fopen($filename, 'c+b');
        flock($tmp, LOCK_SH);
        echo 'data: ', fread($tmp, filesize($filename)),PHP_EOL,PHP_EOL;
        ftruncate($tmp, 0);
        flock($tmp, LOCK_UN);
        fclose($tmp);
        break;
	}


}
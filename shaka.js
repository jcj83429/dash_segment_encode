// myapp.js

var manifestUri = 'getmpd.php?file=/home/archuser/Videos/tyler.mp4';

function initApp() {
  // Install built-in polyfills to patch browser incompatibilities.
  shaka.polyfill.installAll();

  // Check to see if the browser supports the basic APIs Shaka needs.
  if (shaka.Player.isBrowserSupported()) {
    // Everything looks good!
    initPlayer();
  } else {
    // This browser does not have the minimum set of APIs we need.
    console.error('Browser not supported!');
  }
}

function initPlayer() {
  // Create a Player instance.
  var video = document.getElementById('video');
  var player = new shaka.Player(video);

  // Attach player to the window to make it easy to access in the JS console.
  window.player = player;

  player.configure({
    streaming: {
      bufferingGoal: 9,
      retryParameters: {
        timeout: 0,       // timeout in ms, after which we abort a request; 0 means never
        maxAttempts: 1,   // the maximum number of requests before we fail
        baseDelay: 10000,  // the base delay in ms between retries
        backoffFactor: 2, // the multiplicative backoff factor between retries
        fuzzFactor: 0.5,  // the fuzz factor to apply to each retry delay
      }
    }
  });

  // Listen for error events.
  player.addEventListener('error', onErrorEvent);

  // Try to load a manifest.
  // This is an asynchronous process.
  player.load(manifestUri).then(function() {
    // This runs if the asynchronous load is successful.
    console.log('The video has now been loaded!');
  }).catch(onError);  // onError is executed if the asynchronous load fails.
}

function onErrorEvent(event) {
  // Extract the shaka.util.Error object from the event.
  onError(event.detail);
}

function onError(error) {
  // Log the error.
  console.error('Error code', error.code, 'object', error);
}

document.addEventListener('DOMContentLoaded', initApp);

const WebSocket = require('ws');
const { exec } = require('child_process');

const ws = new WebSocket('ws://localhost:8080/app/reverb-key');

ws.on('open', function open() {
  console.log('CONNECTED to Reverb');
  ws.send(JSON.stringify({
    event: 'pusher:subscribe',
    data: { auth: '', channel: 'test-channel' }
  }));
});

ws.on('message', function incoming(data) {
  const message = data.toString();
  console.log('MESSAGE RECEIVED:', message);
  
  if (message.includes('pusher_internal:subscription_succeeded')) {
      console.log('SUBSCRIBED. Firing broadcast event...');
      exec('php artisan broadcast:test "hola mundo"', (error, stdout, stderr) => {
        if (error) {
            console.error(`Error firing broadcast: ${error.message}`);
            process.exit(1);
        }
        console.log(`Broadcast output: ${stdout}`);
      });
  } else if (message.includes('test.event') || message.includes('hola mundo')) {
      console.log('SUCCESS: Event received correctly.');
      setTimeout(() => process.exit(0), 500); // Give it time to flush output
  }
});

ws.on('error', function error(err) {
  console.error('ERROR:', err);
  process.exit(1);
});

setTimeout(() => { 
    console.error('TIMEOUT: Did not receive the expected event in time.'); 
    process.exit(1); 
}, 10000);
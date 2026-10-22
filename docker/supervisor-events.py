#!/usr/bin/python3
"""Stop the container when a required process cannot start or bootstrap fails."""
import os
import signal
import sys
from supervisor import childutils

while True:
    headers, payload = childutils.listener.wait(sys.stdin, sys.stdout)
    event = headers['eventname']
    fields = childutils.get_headers(payload.split('\n', 1)[0])
    fatal = event == 'PROCESS_STATE_FATAL'
    bootstrap_failed = (event == 'PROCESS_STATE_EXITED'
                        and fields.get('processname') == 'bootstrap'
                        and not os.path.isfile('/run/rencoderr/ready'))
    childutils.listener.ok(sys.stdout)
    if fatal or bootstrap_failed:
        sys.stderr.write('Required service failed: ' + payload + '\n')
        sys.stderr.flush()
        open('/run/rencoderr/failed', 'a').close()
        os.kill(os.getppid(), signal.SIGTERM)

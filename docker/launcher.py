#!/usr/bin/python3
"""Hold the configuration lock across Supervisor reloads and forward application logs."""
import os
import select
import signal
import subprocess
import sys
import threading

# FD 9 holds entrypoint's flock. Keep it in PID 1, out of Supervisor's reload cleanup.
os.fstat(9)
log_pipe = os.open('/run/rencoderr/application.log', os.O_RDWR | os.O_NONBLOCK)
finished = threading.Event()


def forward_logs():
    while not finished.is_set():
        if select.select([log_pipe], [], [], 0.1)[0]:
            os.write(sys.stdout.fileno(), os.read(log_pipe, 65536))


reader = threading.Thread(target=forward_logs, daemon=True)
reader.start()
supervisor = subprocess.Popen(['supervisord', '-n', '-c', '/opt/rencoderr/supervisord.conf'], close_fds=True)


def stop(signum, frame):
    if supervisor.poll() is None:
        supervisor.send_signal(signal.SIGTERM)


for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGQUIT, signal.SIGHUP):
    signal.signal(signum, stop)

status = supervisor.wait()
finished.set()
reader.join(timeout=1)
while select.select([log_pipe], [], [], 0)[0]:
    os.write(sys.stdout.fileno(), os.read(log_pipe, 65536))
os.close(log_pipe)
# Reap any orphaned descendants that exited while Supervisor was shutting down.
try:
    while os.waitpid(-1, os.WNOHANG)[0] > 0:
        pass
except ChildProcessError:
    pass
sys.exit(1 if os.path.exists('/run/rencoderr/failed') else status)

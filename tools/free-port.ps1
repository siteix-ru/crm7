param(
  [int]$Start = 8080,
  [int]$Count = 200
)

$addresses = @([System.Net.IPAddress]::Loopback)

if ([System.Net.Sockets.Socket]::OSSupportsIPv6) {
  $addresses += [System.Net.IPAddress]::IPv6Loopback
}

# Порты, которые уже кто-то слушает. Проверка нужна потому, что сокет
# с SO_REUSEADDR (например, llama.cpp на 8080) не мешает TcpListener.Start(),
# и порт ошибочно считается свободным.
$listeners = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()

for ($port = $Start; $port -lt ($Start + $Count); $port++) {
  $free = $true

  if (@($listeners | Where-Object { $_.Port -eq $port }).Count -gt 0) {
    $free = $false
  }

  if ($free) {
    foreach ($address in $addresses) {
      $listener = $null

      try {
        $listener = [System.Net.Sockets.TcpListener]::new($address, $port)
        $listener.Start()
      } catch {
        $free = $false
      } finally {
        if ($null -ne $listener) {
          try { $listener.Stop() } catch { }
        }
      }

      if (-not $free) {
        break
      }
    }
  }

  if ($free) {
    Write-Output $port
    exit 0
  }
}

exit 1

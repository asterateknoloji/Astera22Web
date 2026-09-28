using AsteraSoftPhone.Core;
using pjsua2xamarin.pjsua2;

namespace AsteraSoftPhone.Sip;

public sealed class PjsipService : ISipService
{
    private readonly SipOptions _options;
    private readonly IAppLogger _logger;
    private readonly string _applicationDirectory;
    private readonly SemaphoreSlim _lifecycleLock = new(1, 1);
    private Endpoint? _endpoint;
    private SipAccount? _account;
    private bool _disposed;

    public PjsipService(
        SipOptions options,
        IAppLogger logger,
        string applicationDirectory)
    {
        _options = options;
        _logger = logger;
        _applicationDirectory = applicationDirectory;
    }

    public event EventHandler<RegistrationStateChangedEventArgs>? RegistrationStateChanged;

    public RegistrationState RegistrationState { get; private set; } =
        RegistrationState.Disconnected;

    public async Task StartAsync(CancellationToken cancellationToken = default)
    {
        await _lifecycleLock.WaitAsync(cancellationToken);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (_endpoint is not null)
                return;

            _options.Validate();
            SetState(RegistrationState.Connecting, 0, "PJSIP başlatılıyor");

            var endpoint = new Endpoint();
            endpoint.libCreate();

            var endpointConfig = new EpConfig();
            var pjsipLogDirectory = Path.Combine(_applicationDirectory, "logs");
            Directory.CreateDirectory(pjsipLogDirectory);
            endpointConfig.logConfig.level = 5;
            endpointConfig.logConfig.consoleLevel = 0;
            endpointConfig.logConfig.filename =
                Path.Combine(pjsipLogDirectory, "pjsip.log");
            endpointConfig.uaConfig.userAgent = "AsteraSoftPhone/1.0 PJSIP/2.17";
            endpoint.libInit(endpointConfig);

            var transportConfig = new TransportConfig
            {
                port = _options.LocalSipPort
            };
            endpoint.transportCreate(
                pjsip_transport_type_e.PJSIP_TRANSPORT_UDP,
                transportConfig);
            _logger.Information(
                "SIP TRANSPORT",
                $"UDP transport created on local port {_options.LocalSipPort} (0 = automatic).");

            endpoint.libStart();
            _endpoint = endpoint;

            var accountConfig = new AccountConfig
            {
                idUri = BuildIdentityUri(),
            };
            accountConfig.regConfig.registrarUri =
                $"sip:{_options.Server}:{_options.Port}";
            accountConfig.sipConfig.authCreds.Add(
                new AuthCredInfo(
                    "digest",
                    "*",
                    _options.Username,
                    0,
                    _options.Password));

            SetState(
                RegistrationState.Registering,
                0,
                $"{_options.Server}:{_options.Port} sunucusuna kayıt yapılıyor");

            var account = new SipAccount(this);
            _account = account;
            account.create(accountConfig);
            _logger.Information(
                "SIP REGISTER",
                $"REGISTER started for {_options.Username}@{_options.Server}:{_options.Port} via UDP.");
        }
        catch (Exception exception)
        {
            _logger.Error("ERROR", "PJSIP startup or REGISTER failed.", exception);
            SetState(RegistrationState.RegistrationFailed, 0, exception.Message);
            CleanupNativeObjects();
        }
        finally
        {
            _lifecycleLock.Release();
        }
    }

    public async Task StopAsync(CancellationToken cancellationToken = default)
    {
        await _lifecycleLock.WaitAsync(cancellationToken);
        try
        {
            CleanupNativeObjects();
            SetState(RegistrationState.Disconnected, 0, "Bağlantı kapatıldı");
        }
        finally
        {
            _lifecycleLock.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
            return;

        await StopAsync();
        _disposed = true;
        _lifecycleLock.Dispose();
    }

    private string BuildIdentityUri()
    {
        var displayName = string.IsNullOrWhiteSpace(_options.DisplayName)
            ? _options.Username
            : _options.DisplayName.Replace("\"", string.Empty);
        return $"\"{displayName}\" <sip:{_options.Username}@{_options.Server}>";
    }

    private void OnRegistrationState(OnRegStateParam parameter)
    {
        try
        {
            var code = (int)parameter.code;
            var reason = string.IsNullOrWhiteSpace(parameter.reason)
                ? "SIP yanıtı alınmadı"
                : parameter.reason;
            var accountInfo = _account?.getInfo();

            if (accountInfo?.regIsActive == true && code is >= 200 and < 300)
            {
                SetState(RegistrationState.Registered, code, reason);
                _logger.Information("SIP RESPONSE", $"REGISTER succeeded: {code} {reason}.");
            }
            else if (code is >= 100 and < 200)
            {
                SetState(RegistrationState.Registering, code, reason);
                _logger.Information("SIP RESPONSE", $"REGISTER provisional response: {code} {reason}.");
            }
            else if (code >= 300)
            {
                SetState(RegistrationState.RegistrationFailed, code, reason);
                _logger.Error("SIP RESPONSE", $"REGISTER failed: {code} {reason}.");
            }
            else
            {
                SetState(RegistrationState.Disconnected, code, reason);
                _logger.Information("SIP REGISTER", $"Registration inactive: {code} {reason}.");
            }
        }
        catch (Exception exception)
        {
            _logger.Error("ERROR", "Registration callback failed.", exception);
            SetState(RegistrationState.RegistrationFailed, 0, exception.Message);
        }
    }

    private void SetState(RegistrationState state, int statusCode, string detail)
    {
        RegistrationState = state;
        RegistrationStateChanged?.Invoke(
            this,
            new RegistrationStateChangedEventArgs(state, statusCode, detail));
    }

    private void CleanupNativeObjects()
    {
        if (_account is not null)
        {
            try
            {
                _account.shutdown();
            }
            catch (Exception exception)
            {
                _logger.Error("SIP REGISTER", "Account shutdown failed.", exception);
            }

            _account.Dispose();
            _account = null;
        }

        if (_endpoint is not null)
        {
            try
            {
                _endpoint.libDestroy();
            }
            catch (Exception exception)
            {
                _logger.Error("ERROR", "PJSIP shutdown failed.", exception);
            }

            _endpoint.Dispose();
            _endpoint = null;
        }
    }

    private sealed class SipAccount(PjsipService owner) : Account
    {
        public override void onRegState(OnRegStateParam parameter) =>
            owner.OnRegistrationState(parameter);
    }
}

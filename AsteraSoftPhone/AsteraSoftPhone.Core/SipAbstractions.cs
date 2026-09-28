namespace AsteraSoftPhone.Core;

public enum RegistrationState
{
    Disconnected,
    Connecting,
    Registering,
    Registered,
    RegistrationFailed
}

public sealed class SipOptions
{
    public const string SectionName = "Sip";

    public string Server { get; set; } = string.Empty;
    public ushort Port { get; set; } = 5060;
    public string Transport { get; set; } = "UDP";
    public string Username { get; set; } = string.Empty;
    public string Password { get; set; } = string.Empty;
    public string DisplayName { get; set; } = string.Empty;
    public ushort LocalSipPort { get; set; } = 0;
    public string AudioInput { get; set; } = "Default";
    public string AudioOutput { get; set; } = "Default";

    public void Validate()
    {
        if (string.IsNullOrWhiteSpace(Server))
            throw new InvalidOperationException("Sip:Server is required.");
        if (string.IsNullOrWhiteSpace(Username))
            throw new InvalidOperationException("Sip:Username is required.");
        if (string.IsNullOrWhiteSpace(Password))
            throw new InvalidOperationException(
                "SIP password is not configured. Set ASTERA_SIP_PASSWORD or Sip:Password for development.");
        if (!string.Equals(Transport, "UDP", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("Phase 1 supports UDP transport only.");
    }
}

public sealed class RegistrationStateChangedEventArgs(
    RegistrationState state,
    int statusCode,
    string detail) : EventArgs
{
    public RegistrationState State { get; } = state;
    public int StatusCode { get; } = statusCode;
    public string Detail { get; } = detail;
}

public interface ISipService : IAsyncDisposable
{
    event EventHandler<RegistrationStateChangedEventArgs>? RegistrationStateChanged;

    RegistrationState RegistrationState { get; }
    Task StartAsync(CancellationToken cancellationToken = default);
    Task StopAsync(CancellationToken cancellationToken = default);
}

public interface IAppLogger
{
    void Information(string category, string message);
    void Error(string category, string message, Exception? exception = null);
}

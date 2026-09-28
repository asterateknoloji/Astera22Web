using System.Text.Json;
using AsteraSoftPhone.Core;

namespace AsteraSoftPhone.Infrastructure;

public static class SipConfigurationLoader
{
    public static SipOptions Load(string applicationDirectory)
    {
        var path = Path.Combine(applicationDirectory, "appsettings.json");
        if (!File.Exists(path))
            throw new FileNotFoundException("Configuration file was not found.", path);

        using var document = JsonDocument.Parse(File.ReadAllText(path));
        if (!document.RootElement.TryGetProperty(SipOptions.SectionName, out var section))
            throw new InvalidOperationException("The Sip configuration section is missing.");

        var options = JsonSerializer.Deserialize<SipOptions>(
            section.GetRawText(),
            new JsonSerializerOptions { PropertyNameCaseInsensitive = true })
            ?? throw new InvalidOperationException("The Sip configuration section is invalid.");

        var passwordFromEnvironment = Environment.GetEnvironmentVariable("ASTERA_SIP_PASSWORD");
        if (!string.IsNullOrWhiteSpace(passwordFromEnvironment))
            options.Password = passwordFromEnvironment;

        return options;
    }
}

public sealed class FileAppLogger : IAppLogger, IDisposable
{
    private readonly object _sync = new();
    private readonly StreamWriter _writer;
    private bool _disposed;

    public FileAppLogger(string applicationDirectory)
    {
        var logsDirectory = Path.Combine(applicationDirectory, "logs");
        Directory.CreateDirectory(logsDirectory);
        var path = Path.Combine(logsDirectory, "astera-softphone.log");
        _writer = new StreamWriter(
            new FileStream(path, FileMode.Append, FileAccess.Write, FileShare.ReadWrite))
        {
            AutoFlush = true
        };
    }

    public void Information(string category, string message) =>
        Write("INF", category, message, null);

    public void Error(string category, string message, Exception? exception = null) =>
        Write("ERR", category, message, exception);

    public void Dispose()
    {
        lock (_sync)
        {
            if (_disposed)
                return;

            _disposed = true;
            _writer.Dispose();
        }
    }

    private void Write(string level, string category, string message, Exception? exception)
    {
        lock (_sync)
        {
            if (_disposed)
                return;

            _writer.WriteLine(
                "{0:O} [{1}] [{2}] {3}{4}",
                DateTimeOffset.Now,
                level,
                category,
                message,
                exception is null ? string.Empty : $"{Environment.NewLine}{exception}");
        }
    }
}

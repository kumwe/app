# Integration

Register `Kumwe\Example\ConfigProvider` explicitly, or bind `ExampleServiceInterface` to your own adapter
and leave the provider out; the App fixture does the latter and records it in its migration ledger.

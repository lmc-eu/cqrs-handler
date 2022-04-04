# Changelog

<!-- There should always be "Unreleased" section at the beginning. -->

## Unreleased
- Add handled response type to profiler item
- Handle Impure Decoder
  - Cache response before Impure Decoder decodes the response
  - Fix scope of the fetch query/send command methods, which may overlaps with nested queries in impure decoding

## 1.2.1 - 2022-03-09
- Fix profiling last used decoders when there are none

## 1.2.0 - 2021-08-23
- Use `DecodedValueInterface` instead of `DecodedValue` to allow different implementations

## 1.1.0 - 2021-08-10
- Pass an `$initiator` into `ResponseDecoderInterface::supports` method

## 1.0.1 - 2021-07-28
- Fix stopwatch problem when calling a child query/command
- Fix profiling used decoders when calling a child query/command

## 1.0.0 - 2021-05-13
- Initial implementation

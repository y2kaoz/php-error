<?php

declare(strict_types=1);

namespace Y2KaoZ\PhpError;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @api */
final class ErrorHandler
{
  private static bool $registered = false;

  /** @return list<\Throwable> */
  public static function GetThrowableList(\Throwable $throwable): array
  {
    $errors = [];
    while ($throwable instanceof \Throwable) {
      $errors[] = $throwable;
      $throwable = $throwable->getPrevious();
    }
    return $errors;
  }

  /** @return list<array{message:string,code:int,file:string,line:int,trace:list<string>}> */
  public static function FormatArray(\Throwable $throwable, null|string $removePath = null): array
  {
    $list = [];
    foreach (self::GetThrowableList($throwable) as $t) {
      $message = "Uncaught exception: '{$t->getMessage()}'";
      $code = intval($t->getCode());
      $file = $t->getFile();
      $line = $t->getLine();
      $trace = $t->getTraceAsString();

      $list[] = [
        'message' => is_null($removePath) ? $message : str_replace($removePath, '', $message),
        'code' => $code,
        'file' => is_null($removePath) ? $file : str_replace($removePath, '', $file),
        'line' => $line,
        'trace' => explode("\n", is_null($removePath) ? $trace : str_replace($removePath, '', $trace)),
      ];
    }
    return $list;
  }
  /** @param \Throwable|list<array{message:string,code:int,file:string,line:int,trace:list<string>}> $throwable*/
  public static function FormatHtml(array|\Throwable $throwable, null|string $removePath = null, string $json = ''): string
  {
    $throwable = $throwable instanceof \Throwable ? self::FormatArray($throwable, $removePath) : $throwable;
    $html = <<<STYLE
    <style>
      ul.exceptionList {
        padding: 0; 
        color: red;
        font-family: monospace;
      }
      ul.exceptionList>li.exception {
        list-style: none; 
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 0.25rem 0.5rem;
      }
      ul.exceptionList>li.exception>div.field {
        font-size: small;
        text-transform: uppercase;
        text-decoration: underline;
        text-align: right;
        font-weight: bold;
      }
      ul.exceptionList>li.exception>div.value
      {
        font-style: italic;
      }
    </style>
    STYLE;
    $html .= "\n<ul class='exceptionList'>\n";
    foreach ($throwable as $t) {
      $message = htmlspecialchars($t['message']);
      $code = $t['code'];
      $file = htmlspecialchars($t['file']);
      $line = $t['line'];
      $trace = '<ol><li>' . implode('</li><li>', array_map(htmlspecialchars(...), $t['trace'])) . '</li></ol>';

      $html .= <<< EOE
        <li class='exception'>
          <div class='field'>message:</div><div class='value'>{$message}</div>
          <div class='field'>code:</div><div class='value'>{$code}</div>
          <div class='field'>file:</div><div class='value'>{$file}</div>
          <div class='field'>line:</div><div class='value'>{$line}</div>
          <div class='field'>trace:</div><div class='value'>{$trace}</div>
        </li>
      EOE;
    }
    $html .= "\n</ul>\n";
    if ($json) {
      $json = self::FormatJson($throwable);
      $html .= "<script>\n";
      $html .= "console.log(JSON.parse(`$json`));\n";
      $html .= "</script>\n";
    }
    return $html;
  }
  /** @param \Throwable|list<array{message:string,code:int,file:string,line:int,trace:list<string>}> $throwable*/
  public static function FormatJson(array|\Throwable $throwable, null|string $removePath = null): string
  {
    $throwable = $throwable instanceof \Throwable ? self::FormatArray($throwable, $removePath) : $throwable;
    return json_encode($throwable, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
  }
  public static function IsRegistered(): bool
  {
    return self::$registered;
  }
  public static function Register(
    null|string $removePath = null,
    string $format = 'text/html',
    LoggerInterface $logger = new NullLogger()
  ): void {

    if (self::IsRegistered()) {
      return;
    }

    $exceptionHandler = function (\Throwable $exception) use ($format, $logger, $removePath): void {
      $asArray = self::FormatArray($exception, $removePath);
      $json = self::FormatJson($asArray, $removePath);
      switch ($format) {
        case 'text/html':
          $result = self::FormatHtml($asArray, $removePath, $json);
          break;
        case 'application/json':
          $result = $json;
          break;
        default:
          $result = implode("\n", array_map(strval(...), self::GetThrowableList($exception)));
      }
      error_log($json);
      $logger->error("Uncaught exception: '{$exception->getMessage()}'", $asArray);
      echo $result;
    };

    $errorHandler = function (int $errno, string $errstr, string $errfile, int $errline): bool {
      if ((!(error_reporting() & $errno)) || ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED)) {
        return false;
      }
      throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    };

    set_exception_handler($exceptionHandler);
    set_error_handler($errorHandler);
    self::$registered = true;
  }
}

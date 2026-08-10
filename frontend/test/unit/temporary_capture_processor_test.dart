import 'dart:io';

import 'package:absensi_mahasiswa/features/face_recognition/domain/services/temporary_capture_processor.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  late Directory directory;

  setUp(() async {
    directory = await Directory.systemTemp.createTemp('owned-capture-test-');
  });

  tearDown(() async {
    if (await directory.exists()) await directory.delete(recursive: true);
  });

  test('deletes capture after successful processing', () async {
    final file = await File(
      '${directory.path}/capture.jpg',
    ).writeAsBytes([1, 2]);

    final result = await TemporaryCaptureProcessor().process(
      path: file.path,
      attemptId: 2,
      captureId: 3,
      operation: (capture) async {
        expect(capture.bytes, [1, 2]);
        expect(capture.attemptId, 2);
        expect(capture.captureId, 3);
        return 'ok';
      },
    );

    expect(result, 'ok');
    expect(await file.exists(), isFalse);
  });

  test(
    'deletes capture when detection, embedding, or API operation fails',
    () async {
      for (final stage in ['detect', 'embed', 'api']) {
        final file = await File(
          '${directory.path}/$stage.jpg',
        ).writeAsBytes([1]);
        await expectLater(
          TemporaryCaptureProcessor().process<void>(
            path: file.path,
            attemptId: 1,
            captureId: 1,
            operation: (_) async => throw StateError(stage),
          ),
          throwsA(isA<StateError>().having((e) => e.message, 'message', stage)),
        );
        expect(await file.exists(), isFalse);
      }
    },
  );

  test('attempts delete after read failure', () async {
    var deleted = false;
    final processor = TemporaryCaptureProcessor(
      delete: (_) async => deleted = true,
    );

    await expectLater(
      processor.process<void>(
        path: '${directory.path}/missing.jpg',
        attemptId: 1,
        captureId: 1,
        operation: (_) async {},
      ),
      throwsA(isA<FileSystemException>()),
    );
    expect(deleted, isTrue);
  });

  test('operation failure takes precedence over delete failure', () async {
    final file = await File('${directory.path}/capture.jpg').writeAsBytes([1]);
    final processor = TemporaryCaptureProcessor(
      delete: (_) async => throw StateError('delete'),
    );

    await expectLater(
      processor.process<void>(
        path: file.path,
        attemptId: 1,
        captureId: 1,
        operation: (_) async => throw StateError('api'),
      ),
      throwsA(isA<StateError>().having((e) => e.message, 'message', 'api')),
    );
  });

  test('delete failure is surfaced after a successful operation', () async {
    final file = await File('${directory.path}/capture.jpg').writeAsBytes([1]);
    final processor = TemporaryCaptureProcessor(
      delete: (_) async => throw StateError('delete'),
    );

    await expectLater(
      processor.process<void>(
        path: file.path,
        attemptId: 1,
        captureId: 1,
        operation: (_) async {},
      ),
      throwsA(isA<StateError>().having((e) => e.message, 'message', 'delete')),
    );
  });

  test(
    'delete failure enqueues and retry removes the exact owned path',
    () async {
      SharedPreferences.setMockInitialValues({});
      final preferences = await SharedPreferences.getInstance();
      final deleted = <String>[];
      var fail = true;
      final registry = TemporaryCaptureCleanupRegistry(
        preferences,
        delete: (path) async {
          if (fail) throw StateError('delete');
          deleted.add(path);
        },
      );
      final file = await File(
        '${directory.path}/capture.jpg',
      ).writeAsBytes([1]);
      final processor = TemporaryCaptureProcessor(
        registry: registry,
        delete: (_) async => throw StateError('delete'),
      );

      await expectLater(
        processor.process<void>(
          path: file.path,
          attemptId: 1,
          captureId: 1,
          operation: (_) async {},
        ),
        throwsA(isA<StateError>()),
      );
      fail = false;
      await registry.retryCleanup();
      await registry.retryCleanup();

      expect(deleted, [file.path]);
    },
  );

  test('cleanup drops entries after one hour without deleting them', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    var now = DateTime.utc(2026, 7, 18, 1);
    final deleted = <String>[];
    final registry = TemporaryCaptureCleanupRegistry(
      preferences,
      now: () => now,
      delete: (path) async => deleted.add(path),
    );
    final ownedPath = '${directory.path}/owned.jpg';
    await registry.enqueue(ownedPath);
    now = now.add(const Duration(hours: 1, milliseconds: 1));

    await registry.retryCleanup();

    expect(deleted, isEmpty);
  });
}

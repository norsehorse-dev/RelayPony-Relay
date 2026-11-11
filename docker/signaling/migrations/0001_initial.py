from django.db import migrations, models


class Migration(migrations.Migration):

    initial = True

    dependencies = []

    operations = [
        migrations.CreateModel(
            name='RelayDatum',
            fields=[
                ('id', models.BigAutoField(primary_key=True, serialize=False)),
                ('recipient', models.CharField(max_length=128)),
                ('session', models.CharField(max_length=64)),
                ('payload', models.TextField()),
                ('expires_at', models.DateTimeField(db_index=True)),
            ],
            options={
                'db_table': 'relaypony_relaydata',
                'indexes': [models.Index(fields=['recipient', 'session', 'expires_at'], name='relaypony_r_recipie_6d396c_idx')],
            },
        ),
        migrations.CreateModel(
            name='SignalBlob',
            fields=[
                ('id', models.BigAutoField(primary_key=True, serialize=False)),
                ('recipient', models.CharField(max_length=128)),
                ('payload', models.TextField()),
                ('expires_at', models.DateTimeField(db_index=True)),
            ],
            options={
                'db_table': 'relaypony_signal',
                'indexes': [models.Index(fields=['recipient', 'expires_at'], name='relaypony_s_recipie_612a28_idx')],
            },
        ),
    ]

